<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
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
 *
 * runDue() is the timer (app:automation:run --only=tetraminator, design decision 4): a thin batch that picks the due
 * payments (TetraminatorPollSchedule) and calls reconcile() for each. On top of it, and only for open payments past their
 * invoice, it expires: after a well-formed "not paid" from 70 minutes on, and at 24 hours whatever the answer was
 * (PaymentSettlementService::expireUnpaid(), which leaves the order open and sends late money to review). An error
 * answer never expires a payment before that.
 */
final class TetraminatorReconciler
{
    /** TetraminatorGatewayPlugin::CODE. The plugin ships outside the core, so the core cannot refer to the class. */
    public const PLUGIN_CODE = 'tetraminator';
    /** The notify endpoint's settlement source. */
    public const SOURCE_WEBHOOK = 'tetraminator_webhook';
    /** The timer's settlement and expiry source. */
    public const SOURCE_TIMER = 'tetraminator_timer';
    /** Limits of one timer run (design decision 4): inquiries, seconds, consecutive transport / 5xx errors. */
    public const MAX_INQUIRIES = 40;
    public const TIME_BUDGET_SECONDS = 150;
    public const MAX_CONSECUTIVE_ERRORS = 3;
    /** Payment::isRejectedUnpaid(), as columns: the rejections after which late money must still reach review. */
    private const REJECTED_UNPAID_SOURCES = [Payment::SOURCE_ORDER_EXPIRY, Payment::SOURCE_CUSTOMER_CANCEL, Payment::SOURCE_GATEWAY_INVOICE_EXPIRY, Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED];
    private const REJECTED_UNPAID_NOTES = [Payment::NOTE_ORDER_EXPIRY, Payment::NOTE_CUSTOMER_CANCEL];
    /** Due groups, in the order a run asks: open inside the invoice window, open past it, rejected-unpaid sweeps. */
    private const GROUP_OPEN = 0;
    private const GROUP_OVERDUE = 1;
    private const GROUP_LATE = 2;
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
     * One timer run at $now. Nothing to do (no Tetraminator payment, the plugin never installed) is a run with zero
     * counts. Never inside a transaction: reconcile() asks the provider with no lock held.
     *
     * @param float|null $budgetSeconds the run's time budget (tests); TIME_BUDGET_SECONDS otherwise
     */
    public function runDue(\DateTimeImmutable $now, bool $dryRun = false, ?float $budgetSeconds = null): TetraminatorRunSummary
    {
        $stopAt = microtime(true) + ($budgetSeconds ?? self::TIME_BUDGET_SECONDS);
        $summary = new TetraminatorRunSummary();
        $summary->dryRun = $dryRun;
        $tick = TetraminatorPollSchedule::tick($now);

        $rows = $this->candidates($tick);
        $summary->candidates = count($rows);
        $unavailable = $this->unavailableGateways($rows);
        $summary->driverUnavailable = in_array(true, $unavailable, true);

        $due = [];
        foreach ($rows as $row) {
            $open = PaymentStatus::PENDING === $row['status'];
            $age = (int) TetraminatorPollSchedule::age($row['expiresAt'], $tick);
            if (isset($unavailable[$row['gatewayId']]) || !TetraminatorPollSchedule::isDue($age, $open)) {
                continue;
            }
            $group = !$open ? self::GROUP_LATE : ($age < TetraminatorPollSchedule::OVERDUE_AGE ? self::GROUP_OPEN : self::GROUP_OVERDUE);
            $due[] = ['id' => $row['id'], 'age' => $age, 'open' => $open, 'group' => $group, 'expiresAt' => $row['expiresAt']->getTimestamp()];
        }
        usort($due, static fn (array $a, array $b): int => [$a['group'], $a['expiresAt'], $a['id']] <=> [$b['group'], $b['expiresAt'], $b['id']]);
        $summary->due = count($due);

        if ($dryRun) {
            $summary->leftOver = max(0, $summary->due - self::MAX_INQUIRIES);

            return $summary;
        }

        $consecutiveErrors = 0;
        foreach ($due as $index => $item) {
            $stop = match (true) {
                $summary->accountRefused => TetraminatorRunSummary::STOP_ACCOUNT_REFUSED,
                $consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS => TetraminatorRunSummary::STOP_ERRORS,
                $summary->asked >= self::MAX_INQUIRIES => TetraminatorRunSummary::STOP_LIMIT,
                microtime(true) >= $stopAt => TetraminatorRunSummary::STOP_TIME_BUDGET,
                default => null,
            };
            if (null !== $stop) {
                $summary->stoppedBy = $stop;
                $summary->leftOver = count($due) - $index;
                break;
            }

            $outcome = $this->reconcile($item['id'], self::SOURCE_TIMER);
            if (TetraminatorReconcileOutcome::SKIPPED !== $outcome) {
                ++$summary->asked;
            }
            $consecutiveErrors = TetraminatorReconcileOutcome::VERIFY_FAILED === $outcome ? $consecutiveErrors + 1 : 0;
            $this->count($summary, $outcome);

            if ($item['open'] && TetraminatorReconcileOutcome::NOT_PAID === $outcome && $item['age'] >= TetraminatorPollSchedule::OVERDUE_AGE) {
                $this->expire($item['id'], $summary, false);
            } elseif ($item['open'] && $item['age'] >= TetraminatorPollSchedule::GIVE_UP_AGE && in_array($outcome, [TetraminatorReconcileOutcome::VERIFY_FAILED, TetraminatorReconcileOutcome::ACCOUNT_REFUSED], true)) {
                $this->expire($item['id'], $summary, true);
            }
        }

        if (null !== $summary->stoppedBy) {
            $this->logger->warning('Tetraminator timer: stopped before the end of the due list; the rest waits for the next run.', ['stopped_by' => $summary->stoppedBy, 'left_over' => $summary->leftOver, 'asked' => $summary->asked]);
        }
        $summary->overdueUnanswered = $this->overdueUnanswered($now);
        if ($summary->overdueUnanswered > 0) {
            $this->logger->error('Tetraminator timer: open payments still have no well-formed answer an hour past their invoice.', ['count' => $summary->overdueUnanswered]);
        }

        return $summary;
    }

    /**
     * Tetraminator payments that may be due at $tick, with a stored pay_id and an invoice expiry: open ones at least
     * MIN_AGE old (no upper bound: an open one past 24 h is expired), rejected-unpaid ones up to the last sweep. The
     * status index narrows it; rows are plain arrays.
     *
     * @return list<array{id: int, status: string, expiresAt: \DateTimeImmutable, gatewayId: int}>
     */
    private function candidates(int $tick): array
    {
        $created = static fn (int $age): \DateTimeImmutable => (new \DateTimeImmutable('@'.($tick - $age + TetraminatorPollSchedule::INVOICE_LIFETIME_SECONDS)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $qb = $this->entityManager->createQueryBuilder();
        /** @var list<array{id: int|string, status: string, expiresAt: \DateTimeImmutable, gatewayId: int|string}> $rows */
        $rows = $qb
            ->select('p.id AS id', 'p.status AS status', 'p.expiresAt AS expiresAt', 'g.id AS gatewayId')
            ->from(Payment::class, 'p')
            ->join('p.gateway', 'g')
            ->where('p.status IN (:statuses)')
            ->andWhere('g.pluginCode = :code')
            ->andWhere('p.expiresAt IS NOT NULL AND p.expiresAt <= :youngest')
            ->andWhere("p.gatewayTransactionId IS NOT NULL AND p.gatewayTransactionId <> ''")
            ->andWhere($qb->expr()->orX(
                'p.status = :pending',
                $qb->expr()->andX('p.expiresAt > :oldestLate', $qb->expr()->orX('p.confirmationSource IN (:sources)', 'p.adminNote IN (:notes)')),
            ))
            ->setParameter('statuses', [PaymentStatus::PENDING, PaymentStatus::REJECTED])
            ->setParameter('pending', PaymentStatus::PENDING)
            ->setParameter('code', self::PLUGIN_CODE)
            ->setParameter('youngest', $created(TetraminatorPollSchedule::MIN_AGE))
            ->setParameter('oldestLate', $created(max(TetraminatorPollSchedule::LATE_SWEEPS) + TetraminatorPollSchedule::TICK_SECONDS))
            ->setParameter('sources', self::REJECTED_UNPAID_SOURCES)
            ->setParameter('notes', self::REJECTED_UNPAID_NOTES)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'status' => $row['status'], 'expiresAt' => $row['expiresAt'], 'gatewayId' => (int) $row['gatewayId']], $rows);
    }

    /**
     * Gateways among the candidates whose driver cannot be resolved (plugin removed or disabled): their payments are
     * not asked about. Keyed by id; the value says whether the gateway has an open payment, which makes the run fail
     * (a rejected-unpaid one alone is not worth an alert).
     *
     * @param list<array{id: int, status: string, expiresAt: \DateTimeImmutable, gatewayId: int}> $rows
     *
     * @return array<int, bool>
     */
    private function unavailableGateways(array $rows): array
    {
        $unavailable = [];
        foreach (array_unique(array_column($rows, 'gatewayId')) as $gatewayId) {
            try {
                $gateway = $this->entityManager->find(PaymentGateway::class, $gatewayId) ?? throw new \RuntimeException('gateway not found');
                $this->gatewayRegistry->resolve($gateway);
            } catch (\Throwable) {
                $open = count(array_filter($rows, static fn (array $row): bool => $row['gatewayId'] === $gatewayId && PaymentStatus::PENDING === $row['status']));
                $unavailable[$gatewayId] = $open > 0;
                if ($open > 0) {
                    $this->logger->error('Tetraminator timer: the gateway driver is unavailable while it has open payments; an admin must check the plugin.', ['gateway_id' => $gatewayId, 'open_payments' => $open]);
                }
            }
        }

        return $unavailable;
    }

    private function count(TetraminatorRunSummary $summary, TetraminatorReconcileOutcome $outcome): void
    {
        match ($outcome) {
            TetraminatorReconcileOutcome::SETTLED => ++$summary->settled,
            TetraminatorReconcileOutcome::NEEDS_REVIEW, TetraminatorReconcileOutcome::PROVISIONING_FAILED => ++$summary->review,
            TetraminatorReconcileOutcome::NOT_PAID => ++$summary->notPaid,
            TetraminatorReconcileOutcome::VERIFY_FAILED => ++$summary->verifyFailed,
            TetraminatorReconcileOutcome::ACCOUNT_REFUSED => $summary->accountRefused = true,
            TetraminatorReconcileOutcome::ERROR => ++$summary->errors,
            TetraminatorReconcileOutcome::SKIPPED, TetraminatorReconcileOutcome::ALREADY_SETTLED => null,
        };
    }

    /**
     * expireUnpaid() locks the payment and changes it only while it is still pending: a webhook that settled it in the
     * meantime wins, and one that comes later finds it rejected-unpaid and parks the money for review.
     */
    private function expire(int $paymentId, TetraminatorRunSummary $summary, bool $unanswered): void
    {
        try {
            $payment = $this->entityManager->find(Payment::class, $paymentId);
            if (!$payment instanceof Payment) {
                return;
            }
            $result = $this->settlement->expireUnpaid($payment, self::SOURCE_TIMER);
            if (SettlementOutcome::REJECTED !== $result->outcome) {
                return;
            }
            ++$summary->expired;
            if ($unanswered) {
                ++$summary->expiredUnanswered;
                $this->logger->error('Tetraminator timer: expired after 24 hours without a well-formed answer; compare with the Tetraminator panel.', ['payment_id' => $paymentId]);
            }
        } catch (\Throwable $e) {
            ++$summary->errors;
            $this->logger->error('Tetraminator timer: expiring the payment failed.', ['payment_id' => $paymentId, 'error' => $e::class]);
        }
    }

    /** Open Tetraminator payments whose invoice expired over an hour ago: none of their answers was well-formed. */
    private function overdueUnanswered(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->join('p.gateway', 'g')
            ->where('p.status = :pending')
            ->andWhere('g.pluginCode = :code')
            ->andWhere('p.expiresAt IS NOT NULL AND p.expiresAt <= :limit')
            ->andWhere("p.gatewayTransactionId IS NOT NULL AND p.gatewayTransactionId <> ''")
            ->setParameter('pending', PaymentStatus::PENDING)
            ->setParameter('code', self::PLUGIN_CODE)
            ->setParameter('limit', $now->modify('-1 hour'))
            ->getQuery()
            ->getSingleScalarResult();
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
