<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduled retry of additional inbounds (docs/design/multi-inbound-plans.md 3.2, phase M2b;
 * app:services:attach-inbounds, app:automation:run --only=attach-inbounds). Only with plans.multi_inbound_enabled and
 * plans.attach_retry_enabled on; only ACTIVE services (suspended, expired and deleted ones are never touched; one that
 * becomes active again is picked up by the next run).
 *
 * A service is due when one of its vpn_service_inbound rows is failed with attempts left and next_attempt_at NULL or
 * past, pending with a stale claim, or attached / failed and not compared with the panel for CHECK_EVERY_HOURS. For each
 * due service, in order of id, in batches with a pause between them:
 *
 * 1. Reconcile: one read-only GET of the client's inbounds, no lock held. Then, in a short transaction (the service row
 *    locked by primary key, then its rows), only rows unchanged since they were read before the GET are written:
 *    - attached, but the client is not on that inbound any more (removed by hand on the panel): failed, attempts start
 *      again from 0, so step 2 attaches it again (the owner's decision: the plan promises every inbound);
 *    - failed or stale pending, but the panel already has it: attached, without counting an attempt;
 *    - the others: checked_at only.
 *    A client missing from the panel leaves the rows as they are, checked_at aside (usage sync reports the client; step
 *    2 records why). A panel that cannot be read ends the service here: nothing is written and no attempt is used up.
 * 2. Retry: ServiceInboundAttacher::retry() in its scheduled mode: the due failed rows with attempts left, one attach
 *    call with the missing ids, back-off on failure. Its claim makes two runs at once (or a run and an admin's "Retry
 *    attach") safe: the second finds nothing to claim.
 *
 * Nothing here counts towards a panel's "degraded" state (a bulk run, as in H0). A panel that cannot be read for
 * MAX_CONSECUTIVE_FAILURES services in a row is not asked again in this run; the other panels go on.
 *
 * Finally the admins are alerted once (InboundAttachAlerts), also when only the retry toggle is off.
 */
final class InboundAttachScheduler
{
    public const MAX_CONSECUTIVE_FAILURES = 5;
    public const CHECK_EVERY_HOURS = 6;
    public const SOURCE = 'scheduled_attach';
    public const REMOVED_ON_PANEL = 'The client was no longer on this inbound on the panel (found by the scheduled check).';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly MultiInboundSettings $settings,
        private readonly ServiceInboundAttacher $attacher,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
        private readonly InboundAttachAlerts $alerts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int                                                 $limit     the most services looked at in this run
     * @param \Closure(int, string, InboundAttachRun): void|null $onService called after each service with its id and
     *                                                                       what happened (for the console)
     */
    public function run(int $limit, bool $dryRun = false, int $batchSize = 50, int $pauseMs = 200, ?\Closure $onService = null): InboundAttachRun
    {
        if (!$this->settings->enabled()) {
            return new InboundAttachRun(InboundAttachRun::SWITCHED_OFF);
        }
        if (!$this->settings->attachRetryEnabled()) {
            $run = new InboundAttachRun(InboundAttachRun::DISABLED);
            $run->alertsSent = $dryRun ? 0 : $this->alerts->send();

            return $run;
        }

        $run = new InboundAttachRun();
        $maxAttempts = $this->settings->maxAttempts();
        $limit = max(1, $limit);
        $batchSize = max(1, min($batchSize, $limit));
        if ($dryRun) {
            $run->services = count($this->dueServiceIds(0, $limit, $maxAttempts));

            return $run;
        }

        $failuresInARow = [];
        $lastId = 0;
        $seen = 0;
        while ($seen < $limit) {
            $ids = $this->dueServiceIds($lastId, min($batchSize, $limit - $seen), $maxAttempts);
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $serviceId) {
                $what = $this->one($serviceId, $run, $failuresInARow);
                ++$seen;
                $lastId = $serviceId;
                if (null !== $onService) {
                    $onService($serviceId, $what, $run);
                }
            }
            // Each service was written on its own; the next batch reads fresh rows.
            $this->entityManager->clear();
            if (count($ids) === $batchSize && $seen < $limit && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }
        $run->alertsSent = $this->alerts->send();
        $this->logger->info('scheduled_inbound_attach_run', $run->toArray());

        return $run;
    }

    /**
     * @param array<int, int> $failuresInARow panel id => services in a row whose panel could not be read
     *
     * @return string what happened, for the console (no credential, no panel address)
     */
    private function one(int $serviceId, InboundAttachRun $run, array &$failuresInARow): string
    {
        $service = $this->entityManager->find(VpnService::class, $serviceId);
        $panel = $service?->getPanel();
        if (!$service instanceof VpnService || VpnServiceStatus::ACTIVE !== $service->getStatus() || null === $panel) {
            ++$run->skipped;

            return 'skipped (not active)';
        }
        $panelId = (int) $panel->getId();
        if (isset($run->stoppedPanels[$panelId])) {
            ++$run->skipped;

            return sprintf('skipped (panel #%d stopped for this run)', $panelId);
        }
        $driver = $this->driverRegistry->resolve($panel);
        if (!$driver instanceof InboundAttachDriverInterface) {
            ++$run->skipped;

            return 'skipped (panel not in modern mode)';
        }
        ++$run->services;

        $before = $this->rows($serviceId);
        $changed = false;
        $readFailed = false;
        try {
            $onPanel = $driver->clientInboundIds((string) $service->getRemoteId(), $panel, false);
            if (null === $onPanel) {
                ++$run->clientMissing;
                $read = 'client not on the panel';
                $this->stampChecked($serviceId, array_keys($before));
            } else {
                [$read, $changed] = $this->reconcile($serviceId, $before, $onPanel, $run);
            }
        } catch (\Throwable $e) {
            $readFailed = true;
            ++$run->panelFailures;
            $read = 'panel not read: '.ServiceErrorText::redact($service, $e->getMessage(), 160);
            $this->logger->warning('scheduled_inbound_attach_read_failed', ['service_id' => $serviceId, 'panel_id' => $panelId, 'error' => ServiceErrorText::redact($service, $e->getMessage(), 255)]);
        }
        $failuresInARow[$panelId] = $readFailed ? ($failuresInARow[$panelId] ?? 0) + 1 : 0;
        if ($readFailed) {
            // A panel that cannot be read is not written to either: no attempt is used up while it is down.
            if ($failuresInARow[$panelId] >= self::MAX_CONSECUTIVE_FAILURES) {
                $run->stoppedPanels[$panelId] = $serviceId;
                $this->logger->error('scheduled_inbound_attach_panel_stopped', ['panel_id' => $panelId, 'failures_in_a_row' => $failuresInARow[$panelId], 'service_id' => $serviceId]);
            }

            return $read;
        }

        $retry = $this->attacher->retry($service, self::SOURCE, true);
        $retried = match ($retry->outcome) {
            InboundAttachRetry::DONE => sprintf('retried: %d attached, %d still failed', $retry->attached, $retry->failed),
            InboundAttachRetry::FAILED => sprintf('retried: %d still failed', $retry->failed),
            InboundAttachRetry::IN_PROGRESS => 'retry in progress elsewhere',
            default => 'nothing to retry',
        };
        $run->attached += $retry->attached;
        $run->stillFailed += $retry->failed;
        if ($changed && 0 === $retry->attached) {
            // The client's inbounds changed without an attach (which refreshes them itself): the stored links follow.
            $this->panelLinkSnapshot->refresh($service, self::SOURCE, true, false);
        }

        return $read.'; '.$retried;
    }

    /**
     * The service's rows with the panel id of their inbound as it is now (the attach step uses the same).
     *
     * @return array<int, array{status: string, updated_at: string, remote: int|null}> row id => what was read
     */
    private function rows(int $serviceId): array
    {
        $rows = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT r.id, r.status, r.updated_at, i.remote_inbound_id FROM vpn_service_inbound r JOIN vpn_inbound i ON i.id = r.inbound_id WHERE r.service_id = ? ORDER BY r.id',
            [$serviceId],
        ) as $row) {
            $remote = trim((string) $row['remote_inbound_id']);
            $rows[(int) $row['id']] = ['status' => (string) $row['status'], 'updated_at' => (string) $row['updated_at'], 'remote' => ctype_digit($remote) ? (int) $remote : null];
        }

        return $rows;
    }

    /**
     * Writes what the panel says to the rows still as they were read before the GET (see the class comment).
     *
     * @param array<int, array{status: string, updated_at: string, remote: int|null}> $before
     * @param list<int>                                                               $onPanel the client's inbounds on the panel
     *
     * @return array{string, bool} what happened (for the console); whether a row's state changed
     */
    private function reconcile(int $serviceId, array $before, array $onPanel, InboundAttachRun $run): array
    {
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');
        $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
        $removed = $found = $checked = [];
        foreach ($before as $rowId => $row) {
            $on = null !== $row['remote'] && in_array($row['remote'], $onPanel, true);
            $stalePending = VpnServiceInbound::STATUS_PENDING === $row['status'] && $row['updated_at'] < $stale;
            if (VpnServiceInbound::STATUS_ATTACHED === $row['status'] && !$on) {
                $removed[] = $rowId;
            } elseif ($on && (VpnServiceInbound::STATUS_FAILED === $row['status'] || $stalePending)) {
                $found[] = $rowId;
            } elseif (in_array($row['status'], [VpnServiceInbound::STATUS_ATTACHED, VpnServiceInbound::STATUS_FAILED], true)) {
                $checked[] = $rowId;
            }
        }
        if ([] === $removed && [] === $found && [] === $checked) {
            return ['checked', false];
        }

        [$wasRemoved, $wasFound] = $this->transactional(function (Connection $db) use ($serviceId, $before, $removed, $found, $checked, $at): array {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $wasRemoved = $wasFound = 0;
            foreach ($removed as $rowId) {
                $wasRemoved += (int) $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, attempts = 0, last_error = ?, next_attempt_at = NULL, alert_state = NULL, checked_at = ?, updated_at = ? WHERE id = ? AND status = ? AND updated_at = ?',
                    [VpnServiceInbound::STATUS_FAILED, self::REMOVED_ON_PANEL, $at, $at, $rowId, $before[$rowId]['status'], $before[$rowId]['updated_at']],
                );
            }
            foreach ($found as $rowId) {
                $wasFound += (int) $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, last_error = NULL, next_attempt_at = NULL, attached_at = COALESCE(attached_at, ?), checked_at = ?, updated_at = ? WHERE id = ? AND status = ? AND updated_at = ?',
                    [VpnServiceInbound::STATUS_ATTACHED, $at, $at, $at, $rowId, $before[$rowId]['status'], $before[$rowId]['updated_at']],
                );
            }
            $this->stampChecked($serviceId, $checked, $db);

            return [$wasRemoved, $wasFound];
        });
        $run->foundRemoved += $wasRemoved;
        $run->foundAttached += $wasFound;
        if ($wasRemoved + $wasFound > 0) {
            $this->logger->warning('scheduled_inbound_attach_reconciled', ['service_id' => $serviceId, 'found_removed' => $wasRemoved, 'found_attached' => $wasFound]);
        }

        return 0 === $wasRemoved + $wasFound
            ? ['checked', false]
            : [sprintf('checked, found removed %d, found attached %d', $wasRemoved, $wasFound), true];
    }

    /**
     * Records that these rows were compared with the panel (updated_at is left as it is: a check is not a change of the
     * attachment), so they are not read again for CHECK_EVERY_HOURS.
     *
     * @param list<int> $rowIds
     */
    private function stampChecked(int $serviceId, array $rowIds, ?Connection $db = null): void
    {
        if ([] === $rowIds) {
            return;
        }
        ($db ?? $this->entityManager->getConnection())->executeStatement(
            'UPDATE vpn_service_inbound SET checked_at = ? WHERE service_id = ? AND id IN (?) AND status IN (?)',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $serviceId, $rowIds, [VpnServiceInbound::STATUS_ATTACHED, VpnServiceInbound::STATUS_FAILED]],
            [2 => ArrayParameterType::INTEGER, 3 => ArrayParameterType::STRING],
        );
    }

    /**
     * Services with a row that is due (see the class comment), in order of id, after $afterId.
     *
     * @return list<int>
     */
    private function dueServiceIds(int $afterId, int $count, int $maxAttempts): array
    {
        $now = new \DateTimeImmutable();

        return array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn(
            sprintf(
                'SELECT s.id FROM vpn_service s
                 WHERE s.status = :active AND s.id > :after AND EXISTS (
                   SELECT 1 FROM vpn_service_inbound r WHERE r.service_id = s.id AND (
                     (r.status = :failed AND r.attempts < :max AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= :now))
                     OR (r.status = :pending AND r.updated_at < :stale)
                     OR (r.status IN (:checkable) AND (r.checked_at IS NULL OR r.checked_at <= :checkBefore))))
                 ORDER BY s.id LIMIT %d',
                max(1, $count),
            ),
            [
                'active' => VpnServiceStatus::ACTIVE,
                'after' => $afterId,
                'failed' => VpnServiceInbound::STATUS_FAILED,
                'max' => $maxAttempts,
                'now' => $now->format('Y-m-d H:i:s'),
                'pending' => VpnServiceInbound::STATUS_PENDING,
                'stale' => $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s'),
                'checkable' => [VpnServiceInbound::STATUS_ATTACHED, VpnServiceInbound::STATUS_FAILED],
                'checkBefore' => $now->modify(sprintf('-%d hours', self::CHECK_EVERY_HOURS))->format('Y-m-d H:i:s'),
            ],
            ['checkable' => ArrayParameterType::STRING],
        ));
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        $db = $this->entityManager->getConnection();
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }
    }
}
