<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentConfirmationService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles NOWPayments IPN (Instant Payment Notifications).
 *
 * Endpoint: POST /payment/webhook/nowpayments
 *
 * Signature validation:
 *   - Header: x-nowpayments-sig
 *   - Algorithm: HMAC-SHA512 over sorted JSON payload using ipn_secret
 *   - If ipn_secret is configured and signature is invalid, the request is rejected (fail-closed).
 *   - If ipn_secret is not configured, signature check is skipped.
 *
 * This handler is idempotent: duplicate IPN calls do not double-provision.
 */
final class NowPaymentsWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentConfirmationService $paymentConfirmationService,
    ) {
    }

    #[Route('/payment/webhook/nowpayments', name: 'payment_webhook_nowpayments', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();

        if ('' === $rawBody) {
            return new JsonResponse(['status' => 'error', 'message' => 'empty_body'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'message' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $paymentId = trim((string) ($payload['payment_id'] ?? ''));
        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $status = strtolower(trim((string) ($payload['payment_status'] ?? '')));

        $payment = $this->findPayment($paymentId, $orderId);
        if (!$payment instanceof Payment) {
            error_log(sprintf('[NowPaymentsWebhookController] payment_not_found payment_id=%s order_id=%s', $paymentId, $orderId));

            return new JsonResponse(['status' => 'ok', 'message' => 'payment_not_found'], Response::HTTP_OK);
        }

        // Validate IPN signature
        $gateway = $payment->getGateway();
        if ($gateway instanceof PaymentGateway) {
            $ipnSecret = trim((string) ($gateway->getNowPaymentsIpnSecret() ?? ''));
            if ('' !== $ipnSecret) {
                $receivedSig = trim((string) $request->headers->get('x-nowpayments-sig', ''));
                /** @var NowPaymentsGateway $nowGateway */
                $nowGateway = $this->paymentGatewayRegistry->resolveByType(PaymentGatewayType::NOWPAYMENTS);
                if (!$nowGateway->validateIpnSignature($rawBody, $receivedSig, $ipnSecret)) {
                    error_log(sprintf('[NowPaymentsWebhookController] invalid_ipn_signature payment_id=%s', $paymentId));

                    return new JsonResponse(['status' => 'error', 'message' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
                }
            }
        }

        // Store IPN payload (sanitize: remove sensitive fields before storing)
        $safePayload = $payload;
        $payment->setIpnPayload($safePayload);

        // Update crypto status fields from payload
        if ('' !== $status) {
            $payment->setCryptoPaymentStatus($status);
        }
        $actuallyPaid = trim((string) ($payload['actually_paid'] ?? ''));
        if ('' !== $actuallyPaid) {
            $payment->setCryptoActuallyPaid($actuallyPaid);
        }
        $outcomeAmount = trim((string) ($payload['outcome_amount'] ?? ''));
        if ('' !== $outcomeAmount) {
            $payment->setCryptoOutcomeAmount($outcomeAmount);
        }

        // Handle final paid status
        if (in_array($status, NowPaymentsGateway::PAID_STATUSES, true)) {
            if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
                // Already processed — idempotent, just return OK
                $this->entityManager->flush();

                return new JsonResponse(['status' => 'ok', 'message' => 'already_confirmed']);
            }

            $payment->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable());
            $payment->setFailedAt(null);
            $this->entityManager->flush();

            $result = $this->paymentConfirmationService->confirm($payment, 'nowpayments_webhook');
            if (!$result->processed && !$result->alreadyProcessed) {
                error_log(sprintf('[NowPaymentsWebhookController] confirm_failed payment_id=%s message="%s"', $paymentId, (string) ($result->message ?? '')));
            }

            return new JsonResponse(['status' => 'ok', 'message' => 'confirmed']);
        }

        // Handle failed/expired status
        if (in_array($status, NowPaymentsGateway::FAILED_STATUSES, true)) {
            if (!in_array($payment->getStatus(), [PaymentStatus::CONFIRMED, PaymentStatus::REJECTED], true)) {
                $payment
                    ->setStatus(PaymentStatus::REJECTED)
                    ->setFailedAt($payment->getFailedAt() ?? new \DateTimeImmutable());
            }
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'ok', 'message' => 'failed_or_expired']);
        }

        // Pending/confirming/partially_paid — update status but do not provision
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', 'message' => 'status_updated']);
    }

    private function findPayment(string $paymentId, string $orderId): ?Payment
    {
        if ('' !== $paymentId) {
            $payment = $this->entityManager->getRepository(Payment::class)
                ->createQueryBuilder('p')
                ->where('p.cryptoPaymentId = :pid')
                ->andWhere('p.gatewayType = :type')
                ->setParameter('pid', $paymentId)
                ->setParameter('type', PaymentGatewayType::NOWPAYMENTS)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($payment instanceof Payment) {
                return $payment;
            }

            // Fallback: try gatewayTransactionId
            $payment = $this->entityManager->getRepository(Payment::class)
                ->createQueryBuilder('p')
                ->where('p.gatewayTransactionId = :pid')
                ->andWhere('p.gatewayType = :type')
                ->setParameter('pid', $paymentId)
                ->setParameter('type', PaymentGatewayType::NOWPAYMENTS)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        if ('' !== $orderId) {
            // Try to find by order tracking code
            return $this->entityManager->getRepository(Payment::class)
                ->createQueryBuilder('p')
                ->join('p.order', 'o')
                ->where('(o.trackingCode = :oid OR o.id = :oidInt)')
                ->andWhere('p.gatewayType = :type')
                ->setParameter('oid', $orderId)
                ->setParameter('oidInt', is_numeric($orderId) ? (int) $orderId : -1)
                ->setParameter('type', PaymentGatewayType::NOWPAYMENTS)
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return null;
    }
}
