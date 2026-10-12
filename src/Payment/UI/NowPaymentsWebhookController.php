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
 * Security model (fail-closed):
 *   - The payment is looked up only by references stored when it was created
 *     (payment_id / invoice_id), never by the order_id in the body.
 *   - The gateway must have an ipn_secret; without it every IPN is rejected (403).
 *   - The x-nowpayments-sig HMAC-SHA512 signature must be valid (401 otherwise).
 *   - The IPN body is treated as a notification only: the payment status is re-read from the
 *     NOWPayments API via verifyPayment() before anything is confirmed.
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
        $invoiceId = trim((string) ($payload['invoice_id'] ?? ''));

        $payment = $this->findPayment($paymentId, $invoiceId);
        if (!$payment instanceof Payment) {
            error_log(sprintf('[NowPaymentsWebhookController] payment_not_found payment_id=%s invoice_id=%s', $paymentId, $invoiceId));

            return new JsonResponse(['status' => 'ok', 'message' => 'payment_not_found'], Response::HTTP_OK);
        }

        $gateway = $payment->getGateway();
        $ipnSecret = $gateway instanceof PaymentGateway ? trim((string) ($gateway->getNowPaymentsIpnSecret() ?? '')) : '';
        if ('' === $ipnSecret) {
            error_log(sprintf('[NowPaymentsWebhookController] ipn_secret_not_configured payment=%d', $payment->getId() ?? 0));

            return new JsonResponse(['status' => 'error', 'message' => 'ipn_secret_not_configured'], Response::HTTP_FORBIDDEN);
        }

        /** @var NowPaymentsGateway $nowGateway */
        $nowGateway = $this->paymentGatewayRegistry->resolveByType(PaymentGatewayType::NOWPAYMENTS);
        $receivedSig = trim((string) $request->headers->get('x-nowpayments-sig', ''));
        if (!$nowGateway->validateIpnSignature($rawBody, $receivedSig, $ipnSecret)) {
            error_log(sprintf('[NowPaymentsWebhookController] invalid_ipn_signature payment=%d', $payment->getId() ?? 0));

            return new JsonResponse(['status' => 'error', 'message' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        $payment->setIpnPayload($payload);

        if (PaymentStatus::CONFIRMED === $payment->getStatus()) {
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'ok', 'message' => 'already_confirmed']);
        }

        // Never trust payment_status from the body: ask the NOWPayments API.
        $verification = $nowGateway->verifyPayment($payment, '' !== $paymentId ? ['payment_id' => $paymentId] : []);
        $apiStatus = strtolower(trim((string) ($payment->getCryptoPaymentStatus() ?? '')));

        if ($verification->success && $verification->paid) {
            $this->entityManager->flush();

            $result = $this->paymentConfirmationService->confirm($payment, 'nowpayments_webhook');
            if (!$result->processed && !$result->alreadyProcessed) {
                error_log(sprintf('[NowPaymentsWebhookController] confirm_failed payment=%d message="%s"', $payment->getId() ?? 0, $result->message));

                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'payment_confirmation_failed',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new JsonResponse(['status' => 'ok', 'message' => 'confirmed']);
        }

        if ($verification->success && in_array($apiStatus, NowPaymentsGateway::FAILED_STATUSES, true)) {
            if (!in_array($payment->getStatus(), [PaymentStatus::CONFIRMED, PaymentStatus::REJECTED], true)) {
                $payment
                    ->setStatus(PaymentStatus::REJECTED)
                    ->setFailedAt($payment->getFailedAt() ?? new \DateTimeImmutable());
            }
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'ok', 'message' => 'failed_or_expired']);
        }

        // Pending/confirming/partially_paid or API unavailable — keep waiting, do not provision.
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', 'message' => $verification->success ? 'status_updated' : 'verification_unavailable']);
    }

    private function findPayment(string $paymentId, string $invoiceId): ?Payment
    {
        $candidates = [];
        if ('' !== $paymentId) {
            $candidates[] = ['cryptoPaymentId', $paymentId];
            $candidates[] = ['gatewayTransactionId', $paymentId];
        }
        if ('' !== $invoiceId) {
            $candidates[] = ['cryptoInvoiceId', $invoiceId];
        }

        foreach ($candidates as [$field, $value]) {
            $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([
                $field => $value,
                'gatewayType' => PaymentGatewayType::NOWPAYMENTS,
            ]);
            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        return null;
    }
}
