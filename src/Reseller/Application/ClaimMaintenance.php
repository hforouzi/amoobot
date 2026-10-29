<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\ServiceClaim;
use App\Reseller\Domain\ClaimStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The scheduled side of claim links (app:claims:expire, app:automation:run --only=claims; every 15 minutes):
 *
 * - expire(): open links past their expiry become expired (open_service_id cleared), so lists are honest and a new
 *   link can be made. A link is already refused at claim time once expired; this only tidies up. The links are found
 *   by a plain read and then expired one by one by their primary key (each its own statement, "still open and past
 *   its time" checked again in it): claim rows only, never through a secondary index (ClaimTransactions), so it cannot
 *   deadlock with a claim (claim row, then service row); a claim and the sweep on the same link are serialized by its
 *   row lock, and the claim re-checks the expiry under it.
 * - retryPanelSync(): claimed links whose panel update is pending and due (ClaimPanelSync, with its back-off).
 *
 * Runs whatever reseller.claims_enabled says: closing links and finishing panel updates is only ever tidying up.
 */
class ClaimMaintenance
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClaimPanelSync $panelSync,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return int the links expired (or, with $dryRun, that would be)
     */
    public function expire(int $limit, bool $dryRun = false): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $connection = $this->entityManager->getConnection();
        if ($dryRun) {
            return (int) $connection->fetchOne('SELECT COUNT(*) FROM service_claim WHERE status = ? AND expires_at <= ?', [ClaimStatus::OPEN, $now]);
        }

        $ids = $connection->fetchFirstColumn(
            sprintf('SELECT id FROM service_claim WHERE status = ? AND expires_at <= ? ORDER BY id LIMIT %d', max(1, $limit)),
            [ClaimStatus::OPEN, $now],
        );
        $expired = 0;
        foreach ($ids as $id) {
            $expired += (int) $connection->executeStatement(
                'UPDATE service_claim SET status = ?, open_service_id = NULL, expired_at = ? WHERE id = ? AND status = ? AND expires_at <= ?',
                [ClaimStatus::EXPIRED, $now, (int) $id, ClaimStatus::OPEN, $now],
            );
        }

        return $expired;
    }

    /**
     * @return array{tried: int, synced: int, skipped: int, not_applicable: int, failed: int}
     */
    public function retryPanelSync(int $limit, bool $dryRun = false): array
    {
        /** @var list<ServiceClaim> $due */
        $due = $this->entityManager->getRepository(ServiceClaim::class)->createQueryBuilder('c')
            ->where('c.status = :claimed')
            ->andWhere('c.panelSyncStatus = :pending')
            ->andWhere('c.panelSyncNextAt IS NULL OR c.panelSyncNextAt <= :now')
            ->setParameter('claimed', ClaimStatus::CLAIMED)
            ->setParameter('pending', ServiceClaim::SYNC_PENDING)
            ->setParameter('now', $this->clock->now())
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()->getResult();
        $summary = ['tried' => count($due), 'synced' => 0, 'skipped' => 0, 'not_applicable' => 0, 'failed' => 0];
        if ($dryRun) {
            return $summary;
        }
        foreach ($due as $claim) {
            match ($this->panelSync->sync($claim)) {
                ServiceClaim::SYNC_SYNCED => ++$summary['synced'],
                ServiceClaim::SYNC_SKIPPED => ++$summary['skipped'],
                ServiceClaim::SYNC_NOT_APPLICABLE => ++$summary['not_applicable'],
                default => ++$summary['failed'],
            };
        }

        return $summary;
    }
}
