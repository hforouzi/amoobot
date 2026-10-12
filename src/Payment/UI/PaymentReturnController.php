<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer's browser returning from NOWPayments (success_url / cancel_url, with ?NP_id=...).
 *
 * The page never confirms anything by itself: it asks the provider (verifyPayment), hands a paid
 * result to PaymentSettlementService and then only displays the resulting payment state.
 * The payment is found ONLY by the NOWPayments id stored when it was created; internal payment or
 * order ids in the URL are ignored, because the paid page shows the service's access details.
 */
final class PaymentReturnController extends AbstractController
{
    /** Payment columns holding NOWPayments ids stored at createPayment time (or set by a verified IPN). */
    private const REFERENCE_FIELDS = ['cryptoPaymentId', 'gatewayTransactionId', 'cryptoInvoiceId'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentSettlementService $paymentSettlementService,
    ) {
    }

    #[Route('/payment/success', name: 'payment_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $npId = $this->queryText($request, 'NP_id');
        $payment = $this->findPayment($npId);

        if (!$payment instanceof Payment) {
            $this->logSuccessPage($npId, null, 'not_found');

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

        $statusText = null;
        if (in_array($payment->getStatus(), PaymentStatus::SETTLEABLE, true)) {
            [$payment, $statusText] = $this->verifyAndSettle($payment, $npId);
        }

        $order = $payment->getOrder();
        $state = $this->displayState($payment);
        $service = 'paid' === $state ? $this->findServiceForOrder($order) : null;
        $subscriptionUrl = trim((string) ($service?->getSubscriptionUrl() ?? ''));
        $configText = trim((string) ($service?->getConfigText() ?? ''));

        $this->logSuccessPage($npId, $payment, $state);

        return $this->render('payment/success.html.twig', [
            'state' => $state,
            'payment' => $payment,
            'order' => $order,
            'service' => $service,
            'subscriptionUrl' => '' !== $subscriptionUrl ? $subscriptionUrl : null,
            'configText' => '' !== $configText ? $configText : null,
            'redirectEnabled' => 'paid' === $state && '' !== $subscriptionUrl,
            'statusText' => $statusText ?? $payment->getCryptoPaymentStatus(),
        ]);
    }

    #[Route('/payment/cancel', name: 'payment_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $npId = $this->queryText($request, 'NP_id');
        $payment = $this->findPayment($npId);

        error_log(sprintf(
            '[PaymentReturnController] payment_cancel_page_hit NP_id="%s" payment_found=%s',
            $npId,
            $payment instanceof Payment ? 'yes' : 'no'
        ));

        return $this->render('payment/cancel.html.twig', [
            'payment' => $payment,
            'order' => $payment?->getOrder(),
        ]);
    }

    /**
     * @return array{Payment, ?string}
     */
    private function verifyAndSettle(Payment $payment, string $npId): array
    {
        try {
            $gateway = $payment->getGateway();
            $driver = null !== $gateway
                ? $this->paymentGatewayRegistry->resolve($gateway)
                : $this->paymentGatewayRegistry->resolveByType(PaymentGatewayType::NOWPAYMENTS);
            // NP_id is only a hint; the gateway accepts it only if the API ties it to this payment.
            $verification = $driver->verifyPayment($payment, ['payment_id' => $npId]);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            error_log(sprintf('[PaymentReturnController] verify_failed payment=%d message="%s"', $payment->getId() ?? 0, $e->getMessage()));

            return [$payment, null];
        }

        if ($verification->success && $verification->paid) {
            $result = $this->paymentSettlementService->settle($payment, VerifiedPayment::fromGateway($verification), 'nowpayments_success_return');

            return [$result->payment ?? $payment, $verification->message];
        }

        if ($verification->success && $verification->failed) {
            $result = $this->paymentSettlementService->recordGatewayFailure($payment, 'nowpayments_success_return');

            return [$result->payment ?? $payment, $verification->message];
        }

        return [$payment, $verification->message];
    }

    private function displayState(Payment $payment): string
    {
        return match ($payment->getStatus()) {
            PaymentStatus::CONFIRMED => 'paid',
            PaymentStatus::NEEDS_REVIEW => 'review',
            PaymentStatus::PROVISIONING_FAILED => 'provision_failed',
            PaymentStatus::REJECTED => 'failed',
            default => 'pending',
        };
    }

    private function findPayment(string $npId): ?Payment
    {
        if ('' === $npId) {
            return null;
        }

        foreach (self::REFERENCE_FIELDS as $field) {
            $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([
                $field => $npId,
                'gatewayType' => PaymentGatewayType::NOWPAYMENTS,
            ]);
            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        return null;
    }

    private function findServiceForOrder(Order $order): ?VpnService
    {
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        return $service instanceof VpnService ? $service : null;
    }

    private function queryText(Request $request, string $key): string
    {
        return trim((string) $request->query->get($key, ''));
    }

    private function logSuccessPage(string $npId, ?Payment $payment, string $state): void
    {
        error_log(sprintf(
            '[PaymentReturnController] payment_success_page_hit NP_id="%s" payment_found=%s payment_id=%s order_id=%s payment_status="%s" state=%s',
            $npId,
            $payment instanceof Payment ? 'yes' : 'no',
            $payment?->getId() ?? 'null',
            $payment?->getOrder()->getId() ?? 'null',
            $payment?->getStatus() ?? '',
            $state
        ));
    }
}
