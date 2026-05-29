<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\PaymentApprovalService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentReturnController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentApprovalService $paymentApprovalService,
    ) {
    }

    #[Route('/payment/success', name: 'payment_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $npId = $this->queryText($request, 'NP_id');
        $paymentId = $this->queryText($request, 'payment_id');
        $orderId = $this->queryText($request, 'order_id');
        $lookupId = $this->firstNonEmpty($npId, $paymentId);
        $payment = $this->findPayment($lookupId, $orderId);

        if (!$payment instanceof Payment) {
            $this->logSuccessPage($npId, false, null, null, null, null, false, false, false);

            return $this->render('payment/success.html.twig', [
                'state' => 'not_found',
                'payment' => null,
                'order' => null,
                'service' => null,
                'subscriptionUrl' => null,
                'configText' => null,
                'redirectEnabled' => false,
                'statusText' => null,
            ]);
        }

        $order = $payment->getOrder();
        $statusText = trim((string) ($payment->getCryptoPaymentStatus() ?? $payment->getStatus()));
        $state = 'pending';

        if (PaymentGatewayType::NOWPAYMENTS === (string) $payment->getGatewayType()) {
            /** @var NowPaymentsGateway $gateway */
            $gateway = $this->paymentGatewayRegistry->resolveByType(PaymentGatewayType::NOWPAYMENTS);
            $verification = $gateway->verifyPayment($payment, $this->nowPaymentsVerifyPayload($payment, $lookupId));
            $statusText = trim((string) ($verification->message ?? $payment->getCryptoPaymentStatus() ?? ''));

            if ($verification->success && $verification->paid) {
                if (!$this->isOrderProcessed($order)) {
                    $approval = $this->paymentApprovalService->confirm($payment, 'nowpayments_success_return');
                    if (!$approval->processed && !$approval->alreadyProcessed) {
                        $state = 'provision_failed';
                    } else {
                        $state = 'paid';
                    }
                } else {
                    $state = 'paid';
                }
            } elseif (in_array(strtolower($statusText), NowPaymentsGateway::FAILED_STATUSES, true)) {
                if (PaymentStatus::CONFIRMED !== $payment->getStatus()) {
                    $payment
                        ->setStatus(PaymentStatus::REJECTED)
                        ->setFailedAt($payment->getFailedAt() ?? new \DateTimeImmutable());
                    $this->entityManager->flush();
                }
                $state = 'failed';
            } else {
                $this->entityManager->flush();
                $state = 'pending';
            }
        } elseif (PaymentStatus::CONFIRMED === $payment->getStatus() || $this->isOrderProcessed($order)) {
            $state = 'paid';
        }

        $service = 'paid' === $state ? $this->findServiceForOrder($order) : null;
        $subscriptionUrl = trim((string) ($service?->getSubscriptionUrl() ?? ''));
        $configText = trim((string) ($service?->getConfigText() ?? ''));
        $redirectEnabled = 'paid' === $state && '' !== $subscriptionUrl;

        $this->logSuccessPage($npId, true, $payment, $order, $service, $statusText, '' !== $subscriptionUrl, $redirectEnabled, $this->isOrderProcessed($order));

        return $this->render('payment/success.html.twig', [
            'state' => $state,
            'payment' => $payment,
            'order' => $order,
            'service' => $service,
            'subscriptionUrl' => '' !== $subscriptionUrl ? $subscriptionUrl : null,
            'configText' => '' !== $configText ? $configText : null,
            'redirectEnabled' => $redirectEnabled,
            'statusText' => '' !== $statusText ? $statusText : null,
        ]);
    }

    #[Route('/payment/cancel', name: 'payment_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $npId = $this->queryText($request, 'NP_id');
        $paymentId = $this->queryText($request, 'payment_id');
        $orderId = $this->queryText($request, 'order_id');
        $payment = $this->findPayment($this->firstNonEmpty($npId, $paymentId), $orderId);

        error_log(sprintf(
            '[PaymentReturnController] payment_cancel_page_hit NP_id="%s" payment_found=%s order_id=%s',
            $npId,
            $payment instanceof Payment ? 'yes' : 'no',
            $payment?->getOrder()->getId() ?? ('' !== $orderId ? $orderId : 'null')
        ));

        return $this->render('payment/cancel.html.twig', [
            'payment' => $payment,
            'order' => $payment?->getOrder(),
        ]);
    }

    private function findPayment(string $gatewayId, string $orderId): ?Payment
    {
        if ('' !== $gatewayId) {
            foreach (['cryptoPaymentId', 'gatewayTransactionId', 'authority', 'cryptoInvoiceId'] as $field) {
                $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([$field => $gatewayId]);
                if ($payment instanceof Payment) {
                    return $payment;
                }
            }

            if (is_numeric($gatewayId)) {
                $payment = $this->entityManager->getRepository(Payment::class)->find((int) $gatewayId);
                if ($payment instanceof Payment) {
                    return $payment;
                }
            }
        }

        if ('' !== $orderId) {
            $qb = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p');
            $qb
                ->join('p.order', 'o')
                ->andWhere('o.trackingCode = :orderId OR o.id = :orderIdInt')
                ->setParameter('orderId', $orderId)
                ->setParameter('orderIdInt', is_numeric($orderId) ? (int) $orderId : -1)
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(1);

            $payment = $qb->getQuery()->getOneOrNullResult();

            return $payment instanceof Payment ? $payment : null;
        }

        return null;
    }

    /**
     * @return array{payment_id?: string, invoice_id?: string}
     */
    private function nowPaymentsVerifyPayload(Payment $payment, string $lookupId): array
    {
        $payload = [];
        $cryptoPaymentId = trim((string) ($payment->getCryptoPaymentId() ?? ''));
        $cryptoInvoiceId = trim((string) ($payment->getCryptoInvoiceId() ?? ''));

        if ('' !== $cryptoPaymentId) {
            $payload['payment_id'] = $cryptoPaymentId;
        } elseif ('' !== $lookupId && $lookupId !== $cryptoInvoiceId) {
            $payload['payment_id'] = $lookupId;
        }

        if ('' !== $cryptoInvoiceId) {
            $payload['invoice_id'] = $cryptoInvoiceId;
        } elseif ('' !== $lookupId && '' === ($payload['payment_id'] ?? '')) {
            $payload['invoice_id'] = $lookupId;
        }

        return $payload;
    }

    private function findServiceForOrder(Order $order): ?VpnService
    {
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        return $service instanceof VpnService ? $service : null;
    }

    private function isOrderProcessed(Order $order): bool
    {
        return OrderStatus::PROVISIONED === $order->getStatus() || null !== $order->getProvisionedAt();
    }

    private function queryText(Request $request, string $key): string
    {
        return trim((string) $request->query->get($key, ''));
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ('' !== trim($value)) {
                return trim($value);
            }
        }

        return '';
    }

    private function logSuccessPage(string $npId, bool $found, ?Payment $payment, ?Order $order, ?VpnService $service, ?string $status, bool $hasSubscription, bool $redirectEnabled, bool $orderProcessed): void
    {
        error_log(sprintf(
            '[PaymentReturnController] payment_success_page_hit NP_id="%s" payment_found=%s payment_id=%s order_id=%s order_type="%s" payment_status="%s" order_processed=%s service_found=%s subscriptionUrl_exists=%s redirect_enabled=%s',
            $npId,
            $found ? 'yes' : 'no',
            $payment?->getId() ?? 'null',
            $order?->getId() ?? 'null',
            (string) ($order?->getType() ?? ''),
            (string) ($status ?? ''),
            $orderProcessed ? 'yes' : 'no',
            $service instanceof VpnService ? 'yes' : 'no',
            $hasSubscription ? 'yes' : 'no',
            $redirectEnabled ? 'yes' : 'no'
        ));
    }
}
