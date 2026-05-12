<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ZibalCallbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentConfirmationService $paymentConfirmationService,
    ) {
    }

    #[Route('/payment/callback/zibal', name: 'payment_callback_zibal', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $trackId = trim((string) ($payload['trackId'] ?? $payload['track_id'] ?? ''));
        $authority = trim((string) ($payload['authority'] ?? $payload['Authority'] ?? ''));
        $orderId = (int) ($payload['orderId'] ?? $payload['order_id'] ?? 0);

        $payment = $this->findPaymentByCallbackIds($trackId, $authority, $orderId);
        if (!$payment instanceof Payment) {
            return $this->html('پرداخت یافت نشد.', 404);
        }

        if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
            return $this->html('پرداخت قبلاً تایید شده است.', 200);
        }

        try {
            $verification = $this->paymentGatewayRegistry
                ->resolveByType(PaymentGatewayType::ZIBAL)
                ->verifyPayment($payment, $payload);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            error_log(sprintf('[ZibalCallbackController] verify_exception payment_id=%d message="%s"', $payment->getId() ?? 0, $e->getMessage()));

            return $this->html('خطا در بررسی پرداخت.', 500);
        }

        if (!$verification->success || !$verification->paid) {
            return $this->html('پرداخت تایید نشد.', 400);
        }

        $result = $this->paymentConfirmationService->confirm($payment, 'zibal_callback');
        if ($result->processed || $result->alreadyProcessed) {
            return $this->html('پرداخت با موفقیت تایید شد.', 200);
        }

        return $this->html($result->message, 400);
    }

    private function findPaymentByCallbackIds(string $trackId, string $authority, int $orderId): ?Payment
    {
        $qb = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p');
        $qb->where('p.gatewayType = :gatewayType')
            ->setParameter('gatewayType', PaymentGatewayType::ZIBAL)
            ->setMaxResults(1);

        if ('' !== $trackId && '' !== $authority) {
            $qb->andWhere('(p.gatewayTransactionId = :track OR p.trackingCode = :track OR p.authority = :authority)')
                ->setParameter('track', $trackId)
                ->setParameter('authority', $authority);
        } elseif ('' !== $trackId) {
            $qb->andWhere('(p.gatewayTransactionId = :track OR p.trackingCode = :track)')
                ->setParameter('track', $trackId);
        } elseif ('' !== $authority) {
            $qb->andWhere('p.authority = :authority')
                ->setParameter('authority', $authority);
        } elseif ($orderId > 0) {
            $qb->join('p.order', 'o')
                ->andWhere('o.id = :orderId')
                ->setParameter('orderId', $orderId);
        } else {
            return null;
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function html(string $message, int $status): Response
    {
        return new Response(sprintf(
            '<html><head><meta charset="UTF-8"></head><body><h3>%s</h3></body></html>',
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ), $status);
    }
}
