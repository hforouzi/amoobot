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
 *   link can be made. A link is already refused at claim time once expired; this only tidies up. One UPDATE on claim
 *   rows only (it locks nothing else), so it cannot deadlock with a claim (claim row, then service row): a claim and
 *   the sweep on the same link are serialized by its row lock, and the claim re-checks the expiry under it.
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

        return (int) $connection->executeStatement(
            sprintf('UPDATE service_claim SET status = ?, open_service_id = NULL, expired_at = ? WHERE status = ? AND expires_at <= ? ORDER BY id LIMIT %d', max(1, $limit)),
            [ClaimStatus::EXPIRED, $now, ClaimStatus::OPEN, $now],
        );
    }

    /**
     * @return array{tried: int, synced: int, skipped: int, failed: int}
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
        $summary = ['tried' => count($due), 'synced' => 0, 'skipped' => 0, 'failed' => 0];
        if ($dryRun) {
            return $summary;
        }
        foreach ($due as $claim) {
            match ($this->panelSync->sync($claim)) {
                ServiceClaim::SYNC_SYNCED => ++$summary['synced'],
                ServiceClaim::SYNC_SKIPPED => ++$summary['skipped'],
                default => ++$summary['failed'],
            };
        }

        return $summary;
    }
}
