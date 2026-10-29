<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceErrorText;
use App\Provisioning\Application\ServiceLinkChange;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bulk loop of app:inbound:regenerate-service-configs and app:panel:sync-inbounds --regenerate-configs: the services
 * of some inbounds in order of id, read in batches, each one regenerated (ServiceLinkRegenerator, as a bulk run: a
 * failing panel call never counts towards the panel's "degraded" state) and, with --apply, written and flushed on its
 * own. A pause separates the batches; after MAX_CONSECUTIVE_FAILURES failed services in a row the run stops (the panel
 * is probably down) and says how to resume.
 *
 * Needs $this->entityManager (EntityManagerInterface) and $this->linkRegenerator (ServiceLinkRegenerator).
 */
trait RegeneratesLinksInBatches
{
    use LinkChangeOutput;

    public const MAX_CONSECUTIVE_FAILURES = 5;
    public const DEFAULT_PAUSE_SECONDS = '0.2';

    /**
     * @param list<int> $inboundIds
     *
     * @return array{counts: array<string, int>, stopped: bool, lastId: int}
     */
    private function regenerateInBatches(SymfonyStyle $io, array $inboundIds, string $source, bool $apply, int $afterId, int $batchSize, int $pauseMs): array
    {
        $counts = ['total' => 0, ServiceLinkChange::UPDATED => 0, ServiceLinkChange::UNCHANGED => 0, ServiceLinkChange::KEPT => 0, ServiceLinkChange::SKIPPED => 0, ServiceLinkChange::FAILED => 0, ServiceLinkChange::CHANGED => 0];
        $lastId = $afterId;
        $failuresInARow = 0;
        if ([] === $inboundIds) {
            return ['counts' => $counts, 'stopped' => false, 'lastId' => $lastId];
        }
        while (true) {
            /** @var list<int> $ids */
            $ids = array_map('intval', $this->entityManager->createQueryBuilder()
                ->select('s.id')->from(VpnService::class, 's')
                ->where('IDENTITY(s.inbound) IN (:inbounds)')->andWhere('s.id > :after')
                ->setParameter('inbounds', $inboundIds)->setParameter('after', $lastId)
                ->orderBy('s.id', 'ASC')->setMaxResults($batchSize)
                ->getQuery()->getSingleColumnResult());
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $serviceId) {
                // Loaded one by one: each service is written (and committed) on its own, before the next is read.
                $service = $this->entityManager->find(VpnService::class, $serviceId);
                if (!$service instanceof VpnService) {
                    $lastId = $serviceId;
                    continue;
                }
                try {
                    $change = $this->linkRegenerator->run($service, $source, $apply, true);
                    if ($apply) {
                        $this->entityManager->flush();
                    }
                } catch (\Throwable $e) {
                    $change = new ServiceLinkChange(ServiceLinkChange::FAILED, '-', [], [], false, ServiceErrorText::redact($service, $e->getMessage()));
                }
                ++$counts['total'];
                ++$counts[$change->outcome];
                $this->printChange($io, $serviceId, $change, $apply);
                $lastId = $serviceId;
                if (ServiceLinkChange::FAILED === $change->outcome) {
                    ++$failuresInARow;
                } elseif (ServiceLinkChange::SKIPPED !== $change->outcome) {
                    $failuresInARow = 0;
                }
                if ($failuresInARow >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->entityManager->clear();
                    $io->error(sprintf('Stopped: %d services in a row failed (is the panel down?). Last processed id %d; resume with --after-id=%d.', $failuresInARow, $lastId, $lastId));

                    return ['counts' => $counts, 'stopped' => true, 'lastId' => $lastId];
                }
            }
            $this->entityManager->clear();
            $io->writeln(sprintf('  -- batch done, last processed id %d (resume with --after-id=%d)', $lastId, $lastId));
            if (count($ids) === $batchSize && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        return ['counts' => $counts, 'stopped' => false, 'lastId' => $lastId];
    }

    /**
     * @param array<string, int> $counts
     */
    private function printSummary(SymfonyStyle $io, array $counts, bool $apply): void
    {
        $io->section('Summary');
        $io->listing([
            sprintf('services: %d', $counts['total']),
            sprintf('%s: %d', $apply ? 'updated' : 'would update', $counts[ServiceLinkChange::UPDATED]),
            sprintf('unchanged: %d', $counts[ServiceLinkChange::UNCHANGED]),
            sprintf('kept (panel gave no links): %d', $counts[ServiceLinkChange::KEPT]),
            sprintf('skipped: %d', $counts[ServiceLinkChange::SKIPPED]),
            sprintf('failed (stored links kept): %d', $counts[ServiceLinkChange::FAILED]),
            sprintf('credentials changed meanwhile (nothing written): %d', $counts[ServiceLinkChange::CHANGED]),
        ]);
    }

    /** Seconds (e.g. "0.2") -> milliseconds, 0..60 s. */
    private static function pauseMs(mixed $seconds): int
    {
        return (int) round(1000 * max(0.0, min(60.0, (float) $seconds)));
    }
}
