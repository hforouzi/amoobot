<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks Winapay about payments that are still pending, for customers whose browser never came back to the callback:
 * Winapay has no server-to-server notification, so the money can be paid while nothing here ever hears of it.
 *
 * A payment is settled only on a 100 whose amount equals the expected one exactly, through
 * PaymentSettlementService::settle(), the same call as the callback. That is what keeps the two from settling twice:
 * settle() locks the order and the payment and does nothing unless the payment is still pending or submitted.
 *
 * Everything else leaves the payment as it is. Unlike the callback, a paid answer without a usable amount (101) does
 * not go to review here: a callback may be between its verify and its settle at that moment, and it would be the one
 * to record the payment. Payments are never marked failed.
 *
 * Logs carry the payment id. The Authority and the MerchantID are never logged, and anything a gateway or an exception
 * says passes through SecretRedactor with both values.
 */
final class WinapayReconciler
{
    public const SOURCE = 'winapay_reconcile_command';
    /** WinapayGatewayPlugin::CODE. The plugin ships outside the core, so the core cannot refer to the class. */
    public const PLUGIN_CODE = 'winapay';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentSettlementService $settlement,
        private readonly AmountMatcher $amountMatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Pending payments of active Winapay gateways (the callback also answers for active gateways only) with a stored
     * Authority, created between $newerThan and $olderThan. Newest first: abandoned checkouts stay pending for hours,
     * so oldest first would let them fill every batch and hide a payment that has just passed the minimum age.
     *
     * @return list<array{id: int, orderId: int, createdAt: \DateTimeImmutable}>
     */
    public function candidates(\DateTimeImmutable $olderThan, \DateTimeImmutable $newerThan, int $limit): array
    {
        /** @var list<array{id: int, orderId: int|string, createdAt: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS id', 'IDENTITY(p.order) AS orderId', 'p.createdAt AS createdAt')
            ->from(Payment::class, 'p')
            ->join('p.gateway', 'g')
            ->where('g.pluginCode = :code')
            ->andWhere('g.isActive = true')
            ->andWhere('p.status = :pending')
            ->andWhere("p.authority IS NOT NULL AND p.authority <> ''")
            ->andWhere('p.createdAt <= :olderThan')
            ->andWhere('p.createdAt >= :newerThan')
            ->setParameter('code', self::PLUGIN_CODE)
            ->setParameter('pending', PaymentStatus::PENDING)
            ->setParameter('olderThan', $olderThan)
            ->setParameter('newerThan', $newerThan)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => ['id' => $row['id'], 'orderId' => (int) $row['orderId'], 'createdAt' => $row['createdAt']], $rows);
    }

    public function reconcile(int $paymentId): WinapayReconcileOutcome
    {
        $secrets = [];
        try {
            $payment = $this->entityManager->find(Payment::class, $paymentId);
            if (!$payment instanceof Payment) {
                return WinapayReconcileOutcome::ALREADY_SETTLED;
            }
            // The batch was read a while ago: the callback may have settled this one since.
            $this->entityManager->refresh($payment);
            $gateway = $payment->getGateway();
            $authority = trim((string) $payment->getAuthority());
            if (PaymentStatus::PENDING !== $payment->getStatus() || !$gateway instanceof PaymentGateway || '' === $authority) {
                return WinapayReconcileOutcome::ALREADY_SETTLED;
            }
            $secrets = [$authority, self::merchantId($gateway)];

            return $this->verifyAndSettle($payment, $gateway, $secrets);
        } catch (\Throwable $e) {
            $this->logger->error('Winapay reconcile failed.', ['payment_id' => $paymentId, 'error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return WinapayReconcileOutcome::ERROR;
        }
    }

    /**
     * @param list<string> $secrets
     */
    private function verifyAndSettle(Payment $payment, PaymentGateway $gateway, array $secrets): WinapayReconcileOutcome
    {
        $paymentId = (int) $payment->getId();
        try {
            $driver = $this->gatewayRegistry->resolve($gateway);
        } catch (\Throwable $e) {
            $this->logger->error('Winapay reconcile: the gateway driver is unavailable.', ['payment_id' => $paymentId, 'error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return WinapayReconcileOutcome::ERROR;
        }

        try {
            $verification = $driver->verifyPayment($payment, []);
        } catch (\Throwable $e) {
            $this->logger->warning('Winapay reconcile: verification failed, the payment stays pending.', ['payment_id' => $paymentId, 'error' => SecretRedactor::redact($e->getMessage(), $secrets)]);

            return WinapayReconcileOutcome::VERIFY_FAILED;
        }

        $answer = SecretRedactor::redact((string) $verification->message, $secrets);
        if (!$verification->success) {
            $this->logger->warning('Winapay reconcile: verification failed, the payment stays pending.', ['payment_id' => $paymentId, 'error' => $answer]);

            return WinapayReconcileOutcome::VERIFY_FAILED;
        }
        if (!$verification->paid) {
            $this->logger->info('Winapay reconcile: not paid, the payment stays pending.', ['payment_id' => $paymentId, 'answer' => $answer]);

            return WinapayReconcileOutcome::NOT_PAID;
        }
        if (null === $verification->amount) {
            $this->logger->error('Winapay reconcile: Winapay says paid without an amount (already verified, or incomplete proof). Not settled; compare with the Winapay panel.', ['payment_id' => $paymentId, 'answer' => $answer]);

            return WinapayReconcileOutcome::PAID_WITHOUT_PROOF;
        }

        $match = $this->amountMatcher->matchVerification($payment, $verification);
        if (AmountMatchStatus::MATCH !== $match->status) {
            $this->logger->error('Winapay reconcile: amount mismatch. Not settled; compare with the Winapay panel.', ['payment_id' => $paymentId, 'match' => SecretRedactor::redact($match->describe(), $secrets)]);

            return WinapayReconcileOutcome::AMOUNT_MISMATCH;
        }

        $result = $this->settlement->settle($payment, VerifiedPayment::fromGateway($verification), self::SOURCE);
        $outcome = $result->outcome;
        $context = ['payment_id' => $paymentId, 'outcome' => $outcome->value, 'detail' => SecretRedactor::redact($result->message, $secrets)];

        if (SettlementOutcome::CONFIRMED === $outcome) {
            $this->logger->info('Winapay reconcile: payment settled.', ['payment_id' => $paymentId]);

            return WinapayReconcileOutcome::SETTLED;
        }
        if (SettlementOutcome::ALREADY_PROCESSED === $outcome) {
            if (PaymentStatus::NEEDS_REVIEW === $result->payment?->getStatus()) {
                // Winapay gave this run the 100, so the other path got a 101 and parked it: the proof is here.
                $this->logger->error('Winapay reconcile: another path parked this payment for review, but Winapay confirmed it with the exact amount. An admin can approve it.', $context);

                return WinapayReconcileOutcome::NEEDS_REVIEW;
            }

            return WinapayReconcileOutcome::ALREADY_SETTLED;
        }
        if (SettlementOutcome::NEEDS_REVIEW === $outcome) {
            $this->logger->warning('Winapay reconcile: settlement parked the payment for review.', $context);

            return WinapayReconcileOutcome::NEEDS_REVIEW;
        }
        if (SettlementOutcome::PROVISIONING_FAILED === $outcome) {
            $this->logger->warning('Winapay reconcile: paid, but fulfilment failed; the payment is in the retry list.', $context);

            return WinapayReconcileOutcome::PROVISIONING_FAILED;
        }

        $this->logger->error('Winapay reconcile: settlement failed.', $context);

        return WinapayReconcileOutcome::ERROR;
    }

    private static function merchantId(PaymentGateway $gateway): string
    {
        $config = $gateway->getConfig();
        $merchantId = is_array($config) ? ($config['merchant_id'] ?? null) : null;

        return is_scalar($merchantId) ? trim((string) $merchantId) : '';
    }
}
