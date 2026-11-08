<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One Tetraminator inquiry for one payment (docs/plugins/tetraminator-gateway/design.md, decisions 1, 2 and 10): the
 * notify endpoint's last step, and the step a batch timer repeats per payment (PR 5).
 *
 * The payment is re-read by id each time, so a caller may hold a stale or cleared entity manager. It is asked about
 * only while it belongs to a Tetraminator gateway, has a stored pay_id and still accepts a gateway verification:
 * pending, or rejected unpaid (cancelled, expired, superseded), so late money reaches review through settle(). A
 * confirmed or parked payment is skipped before any HTTP call.
 *
 * The inquiry runs outside any transaction, so no lock is held during it. A paid answer goes to
 * PaymentSettlementService::settle(), the only settler: it locks order and payment, does nothing unless the payment is
 * still settleable (two webhooks, the timer and the return page cannot settle twice), compares the amount, and parks
 * a paid answer without an amount (the plugin's mismatch) for review. Unlike Winapay's verify, the inquiry is a
 * repeatable read, so a paid answer always goes to settle(). Nothing here expires or fails a payment.
 *
 * Logs carry the payment id only. The API key and the pay_id are masked out of anything a gateway or an exception says.
 */
final class TetraminatorReconciler
{
    /** TetraminatorGatewayPlugin::CODE. The plugin ships outside the core, so the core cannot refer to the class. */
    public const PLUGIN_CODE = 'tetraminator';
    /** The notify endpoint's settlement source. */
    public const SOURCE_WEBHOOK = 'tetraminator_webhook';
    /** TetraminatorGatewayPlugin::ERROR_UNAUTHORIZED / ERROR_STORE_DISABLED: rawResponse.error of a 401 / 403. */
    private const ACCOUNT_ERRORS = ['unauthorized', 'store_disabled'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentSettlementService $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reconcile(int $paymentId, string $source): TetraminatorReconcileOutcome
    {
        $secrets = [];
        try {
            $payment = $this->entityManager->find(Payment::class, $paymentId);
            if (!$payment instanceof Payment) {
                return TetraminatorReconcileOutcome::SKIPPED;
            }
            // Another process may have settled it since this entity manager read it.
            $this->entityManager->refresh($payment);
            $gateway = $payment->getGateway();
            $payId = trim((string) $payment->getGatewayTransactionId());
            if (!$gateway instanceof PaymentGateway || self::PLUGIN_CODE !== $gateway->getPluginCode() || '' === $payId || !$payment->acceptsGatewayVerification()) {
                return TetraminatorReconcileOutcome::SKIPPED;
            }
            $secrets = [$payId, self::apiKey($gateway)];

            return $this->verifyAndSettle($payment, $gateway, $source, $secrets);
        } catch (\Throwable $e) {
            $this->logger->error('Tetraminator reconcile failed.', ['payment_id' => $paymentId, 'source' => $source, 'error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return TetraminatorReconcileOutcome::ERROR;
        }
    }

    /**
     * @param list<string> $secrets
     */
    private function verifyAndSettle(Payment $payment, PaymentGateway $gateway, string $source, array $secrets): TetraminatorReconcileOutcome
    {
        $paymentId = (int) $payment->getId();
        $context = ['payment_id' => $paymentId, 'source' => $source];
        try {
            $driver = $this->gatewayRegistry->resolve($gateway);
        } catch (\Throwable $e) {
            $this->logger->error('Tetraminator reconcile: the gateway driver is unavailable.', $context + ['error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return TetraminatorReconcileOutcome::ERROR;
        }

        try {
            $verification = $driver->verifyPayment($payment, []);
        } catch (\Throwable $e) {
            $this->logger->warning('Tetraminator reconcile: the inquiry failed, the payment is unchanged.', $context + ['error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return TetraminatorReconcileOutcome::VERIFY_FAILED;
        }

        $answer = SecretRedactor::redact((string) $verification->message, $secrets);
        if (!$verification->success) {
            if (self::isAccountRefusal($verification)) {
                $this->logger->error('Tetraminator reconcile: the API key or the store was refused; an admin must check the gateway.', $context + ['error' => $answer]);

                return TetraminatorReconcileOutcome::ACCOUNT_REFUSED;
            }
            $this->logger->warning('Tetraminator reconcile: the inquiry failed, the payment is unchanged.', $context + ['error' => $answer]);

            return TetraminatorReconcileOutcome::VERIFY_FAILED;
        }
        // The plugin never reports "failed" (an invoice stays payable); were it to, the payment would stay as it is.
        if (!$verification->paid) {
            $this->logger->info('Tetraminator reconcile: not paid, the payment is unchanged.', $context + ['answer' => $answer]);

            return TetraminatorReconcileOutcome::NOT_PAID;
        }

        // settle() flushes what the plugin recorded (the review evidence in the verify payload) before it re-reads.
        $result = $this->settlement->settle($payment, VerifiedPayment::fromGateway($verification), $source);
        // After a failed attempt the entity manager was cleared: only $result->payment is current.
        $context += ['outcome' => $result->outcome->value, 'status' => $result->payment?->getStatus(), 'detail' => SecretRedactor::redact($result->message, $secrets)];

        switch ($result->outcome) {
            case SettlementOutcome::CONFIRMED:
                $this->logger->info('Tetraminator reconcile: payment settled.', ['payment_id' => $paymentId, 'source' => $source]);

                return TetraminatorReconcileOutcome::SETTLED;
            case SettlementOutcome::ALREADY_PROCESSED:
                return TetraminatorReconcileOutcome::ALREADY_SETTLED;
            case SettlementOutcome::NEEDS_REVIEW:
                $this->logger->warning('Tetraminator reconcile: settlement parked the payment for review.', $context);

                return TetraminatorReconcileOutcome::NEEDS_REVIEW;
            case SettlementOutcome::PROVISIONING_FAILED:
                $this->logger->warning('Tetraminator reconcile: paid, but fulfilment failed; the payment is in the retry list.', $context);

                return TetraminatorReconcileOutcome::PROVISIONING_FAILED;
            default:
                $this->logger->error('Tetraminator reconcile: settlement failed.', $context);

                return TetraminatorReconcileOutcome::ERROR;
        }
    }

    private static function isAccountRefusal(PaymentVerificationResult $verification): bool
    {
        $error = $verification->rawResponse['error'] ?? null;

        return is_string($error) && in_array($error, self::ACCOUNT_ERRORS, true);
    }

    private static function apiKey(PaymentGateway $gateway): string
    {
        $config = $gateway->getConfig();
        $key = is_array($config) ? ($config['api_key'] ?? null) : null;

        return is_scalar($key) ? trim((string) $key) : '';
    }
}
