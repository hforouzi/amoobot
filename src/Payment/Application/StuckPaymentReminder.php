<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reminds the admins again of a payment that waits for them too long: provisioning_failed (paid, the service was not
 * created) or needs_review with its wallet debit held (the customer's money is taken), for more than STUCK_AFTER_HOURS.
 * The first alert was sent when it happened (AdminPaymentAlerter::provisioningFailed() / needsReview()); this one
 * repeats at most once every REMIND_EVERY_HOURS per payment.
 *
 * Run by app:automation:run (task "orders", every 10 minutes). Each reminder is claimed with a conditional UPDATE of
 * payment.stuck_reminded_at (and the payment's status), so overlapping runs or a payment decided meanwhile never alert
 * twice or wrongly.
 */
final class StuckPaymentReminder
{
    public const STUCK_AFTER_HOURS = 6;
    public const REMIND_EVERY_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminPaymentAlerter $alerter,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{due: int, reminded: int}
     */
    public function remind(bool $dryRun = false, int $limit = 100): array
    {
        $now = $this->clock->now();
        $stuckSince = $now->modify(sprintf('-%d hours', self::STUCK_AFTER_HOURS))->format('Y-m-d H:i:s');
        $remindedBefore = $now->modify(sprintf('-%d hours', self::REMIND_EVERY_HOURS))->format('Y-m-d H:i:s');
        $connection = $this->entityManager->getConnection();

        // since: when it got stuck (the provisioning failure, else the verification, else the payment itself).
        $rows = $connection->fetchAllAssociative(
            "SELECT p.id, p.status, COALESCE(p.provisioning_failed_at, p.verified_at, p.created_at) AS since, (d.id IS NOT NULL AND r.id IS NULL) AS held
             FROM payment p
             LEFT JOIN wallet_transaction d ON d.idempotency_key = CONCAT('purchase:payment:', p.id)
             LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
             WHERE (p.status = :failed OR (p.status = :review AND d.id IS NOT NULL AND r.id IS NULL))
               AND COALESCE(p.provisioning_failed_at, p.verified_at, p.created_at) <= :stuckSince
               AND (p.stuck_reminded_at IS NULL OR p.stuck_reminded_at <= :remindedBefore)
             ORDER BY p.id
             LIMIT ".max(1, $limit),
            ['failed' => PaymentStatus::PROVISIONING_FAILED, 'review' => PaymentStatus::NEEDS_REVIEW, 'stuckSince' => $stuckSince, 'remindedBefore' => $remindedBefore]
        );

        $reminded = 0;
        foreach ($rows as $row) {
            if ($dryRun) {
                continue;
            }
            $claimed = $connection->executeStatement(
                'UPDATE payment SET stuck_reminded_at = ? WHERE id = ? AND status = ? AND (stuck_reminded_at IS NULL OR stuck_reminded_at <= ?)',
                [$now->format('Y-m-d H:i:s'), (int) $row['id'], (string) $row['status'], $remindedBefore]
            );
            if (1 !== $claimed) {
                continue;
            }
            $payment = $this->entityManager->find(Payment::class, (int) $row['id']);
            if (!$payment instanceof Payment) {
                continue;
            }
            $this->entityManager->refresh($payment);
            $hours = intdiv(max(0, $now->getTimestamp() - (new \DateTimeImmutable((string) $row['since'], $now->getTimezone()))->getTimestamp()), 3600);
            $this->alerter->stuckReminder($payment, $hours, (bool) $row['held']);
            ++$reminded;
        }
        if ([] !== $rows) {
            $this->logger->warning('Stuck payments: admins reminded.', ['due' => count($rows), 'reminded' => $reminded, 'dry_run' => $dryRun]);
        }

        return ['due' => count($rows), 'reminded' => $reminded];
    }
}
