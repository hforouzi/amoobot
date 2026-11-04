<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\Plan;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\InboundMoveAttachResult;
use App\Provisioning\Domain\InboundMoveDriverInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelCallBudget;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Security\AdminAuditLogger;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Phase 1 of a primary move run (docs/design/plan-inbound-change.md 4.3 steps 1-4, 5.3, 5.4; PR P4): each planned
 * service of the run is put on the target B as well, then switched to it locally, then its links are refreshed. The old
 * inbound A is never removed here (keep-old; phase 2 is P6).
 *
 * Per service, in this order:
 * 1. Claim: a short READ COMMITTED transaction that locks vpn_service by primary key, then its vpn_service_inbound
 *    rows, then its service_inbound_move row (5.3), re-checks every rule under those locks (InboundMovePlanner) and
 *    either sets the move "attaching" (claimed_at, attempts + 1) or "skipped" with the reason. A move another process
 *    claimed less than ServiceInboundAttacher::STALE_CLAIM ago is left alone; an older claim is taken over.
 * 2. Panel, with no transaction and no lock: InboundMoveDriverInterface::attachForMove() (GET; attach B when missing;
 *    GET). A client on neither A nor B, or B still missing, is a failure: back-off by MultiInboundSettings
 *    (attach_retry_delay_minutes × 2^(n-1), at most 6 h), given up after attach_max_attempts.
 * 3. Write: a short READ COMMITTED transaction, same lock order. Only while the move still holds this claim and the
 *    service still has the inbound, UUID, subId and remote id it had at the claim (the identity rule of
 *    PanelLinkSnapshot::storeIfUnchanged()). Then vpn_service.inbound = B, the remote id's inbound and remoteInbound
 *    parts rewritten (every other part byte-identical), inbound_changed_at = now, B's additional row deleted, an
 *    additional row for A when A stays one of the plan's additional inbounds, and the move "switched". If the identity
 *    changed nothing is written and the move goes back to "planned" (picked again at once, once).
 * 4. PanelLinkSnapshot::refresh() with its own lock and rules (the panel's subLinks only; an empty answer keeps the old
 *    links). A switched service whose links were not refreshed (inbound_changed_at after last_access_info_synced_at) is
 *    refreshed by the next pass.
 *
 * Steps 2-4 of a service share one PanelCallBudget of InboundMovePlanner::STEP_BUDGET_SECONDS (at most 4 calls). Every
 * panel call is made with recordHealth=false: a bad run never marks the panel degraded or stops its sales. No order,
 * payment, wallet, user or reseller row is ever locked or written; the run row is changed only by autocommit UPDATEs,
 * never while a service is locked. After MAX_READ_FAILURES_IN_A_ROW services in a row whose panel could not be read, the
 * run pauses itself (one alert); it is resumed only by running the command again with --resume.
 */
final class InboundMoveRunner
{
    public const SOURCE = InboundMovePlanner::SOURCE;
    public const MAX_READ_FAILURES_IN_A_ROW = InboundAttachScheduler::MAX_CONSECUTIVE_FAILURES;
    public const PAUSE_PANEL = 'panel not answering: %d failed panel reads in a row';
    public const OPTIONS_UNREADABLE = 'the panel\'s inbound options could not be read (protocol rules not checked): not moved yet.';

    private const CLAIMED = 'claimed';
    private const NOT_DUE = 'not_due';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly InboundMovePlanner $planner,
        private readonly InboundMoveSettings $settings,
        private readonly MultiInboundSettings $retries,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
        private readonly PanelCallBudget $budget,
        private readonly InboundAttachAlerts $alerts,
        private readonly AdminAuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser = new Sanaei3xuiRemoteIdParser(),
    ) {
    }

    /**
     * One pass over the run's due moves, in order of move id, in batches with a pause between them.
     *
     * @param \Closure(InboundMoveItem): void|null $onItem called after each service (console)
     */
    public function process(int $runId, ?int $batchSize = null, ?int $pauseSeconds = null, ?\Closure $onItem = null): InboundMovePass
    {
        if (!$this->settings->enabled()) {
            return new InboundMovePass(InboundMovePass::SWITCHED_OFF, sprintf('Moving services is switched off (%s): nothing was done.', InboundMoveSettings::ENABLED));
        }
        if ($this->db()->isTransactionActive()) {
            return new InboundMovePass(InboundMovePass::REFUSED, 'Refused inside a transaction: panel calls are never made under someone\'s locks.');
        }
        $run = $this->db()->fetchAssociative('SELECT id, plan_id, kind, status, target_inbound_id FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $run || !in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true) || InboundMoveRun::KIND_PRIMARY !== $run['kind']) {
            return new InboundMovePass(InboundMovePass::NOT_OPEN, false === $run ? sprintf('There is no run #%d.', $runId) : sprintf('Run #%d is %s: nothing to do.', $runId, (string) $run['status']));
        }
        $planId = (int) $run['plan_id'];
        $this->db()->executeStatement(
            'UPDATE inbound_move_run SET status = ?, open_plan_id = plan_id, pause_reason = NULL, started_at = COALESCE(started_at, ?) WHERE id = ? AND status IN (?, ?, ?)',
            [InboundMoveRun::STATUS_RUNNING, self::now(), $runId, ...InboundMoveRun::OPEN_STATUSES],
        );
        $batchSize = max(1, min(InboundMoveSettings::MAX_BATCH_SIZE, $batchSize ?? $this->settings->batchSize()));
        $pauseSeconds = max(0, min(InboundMoveSettings::MAX_PAUSE_SECONDS, $pauseSeconds ?? $this->settings->pauseSeconds()));
        $scope = InboundMoveScope::fromArray(json_decode((string) $this->db()->fetchOne('SELECT scope FROM inbound_move_run WHERE id = ?', [$runId]), true) ?: []);
        $this->planner->forgetPanelReads();

        $pass = new InboundMovePass();
        $lastId = 0;
        $readFailures = 0;
        while (true) {
            $ids = $this->dueMoveIds($runId, $lastId, $batchSize);
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $moveId) {
                $lastId = $moveId;
                $item = $this->one($moveId, $planId, $scope, $runId);
                if (InboundMoveItem::IDENTITY_CHANGED === $item->outcome) {
                    // Picked again at once (4.3 step 3), for the credentials the service has now.
                    $item = $this->one($moveId, $planId, $scope, $runId);
                }
                $this->count($pass, $item);
                if (null !== $onItem) {
                    $onItem($item);
                }
                if (false === $item->panelRead) {
                    ++$readFailures;
                } elseif (true === $item->panelRead) {
                    $readFailures = 0;
                }
                if ($readFailures >= self::MAX_READ_FAILURES_IN_A_ROW) {
                    $this->pause($runId, $planId, sprintf(self::PAUSE_PANEL, $readFailures), $pass);
                    $this->finishPass($runId, $pass);

                    return $pass;
                }
            }
            // Every service was written on its own; the next batch reads fresh rows.
            $this->entityManager->clear();
            if (count($ids) === $batchSize && $pauseSeconds > 0) {
                sleep($pauseSeconds);
            }
        }
        $this->finishPass($runId, $pass);
        $this->finishRun($runId, $planId, $pass);

        return $pass;
    }

    /** One move: a pending link refresh only (switched), or the claim, panel, write and refresh of 4.3 steps 1-4. */
    private function one(int $moveId, int $planId, InboundMoveScope $scope, int $runId): InboundMoveItem
    {
        $move = $this->db()->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ?', [$moveId]);
        if (false === $move) {
            return new InboundMoveItem($moveId, 0, '-', '-', InboundMoveItem::BUSY, 'the move is gone');
        }
        $serviceId = (int) $move['service_id'];
        [$from, $to] = $this->titles($move);
        $service = $this->entityManager->find(VpnService::class, $serviceId);
        if (!$service instanceof VpnService) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'the service is gone');
        }
        if (ServiceInboundMove::STATE_SWITCHED === $move['state']) {
            $refresh = $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): PanelLinkRefresh => $this->refreshLinks($service));

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SNAPSHOT, self::refreshText($refresh), PanelLinkRefresh::FAILED !== $refresh->outcome, false, $refresh->outcome);
        }

        $target = null === $move['to_inbound_id'] ? null : $this->entityManager->find(VpnInbound::class, (int) $move['to_inbound_id']);
        $plan = $this->entityManager->find(Plan::class, $planId) ?? throw new \LogicException('The run\'s plan is gone.');
        $claim = $this->claim($move, $target, $scope, $runId);
        if (self::NOT_DUE === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile, or no longer due');
        }
        if (null !== $claim['skip']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText($claim['skip'], $claim['otherRun']));
        }
        \assert($target instanceof VpnInbound);
        $this->entityManager->refresh($service);

        // 4.4 protocol mix: after the claim, outside the transaction (it may read inbounds/options).
        try {
            $mix = $this->planner->protocolMix($serviceId, $plan, $target, null === $move['from_inbound_id'] ? null : (int) $move['from_inbound_id']);
        } catch (\Throwable) {
            // Unreadable options, whatever the reason: not moved yet (below).
            $mix = null;
        }
        if (true === $mix) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_PROTOCOL_MIX);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText(InboundMovePlanner::SKIP_PROTOCOL_MIX));
        }
        if (null === $mix) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], self::OPTIONS_UNREADABLE);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, false);
        }

        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->switch($service, $target, $plan, $move, $claim, $from, $to));
    }

    /**
     * Steps 2-4 for a claimed move.
     *
     * @param array<string, mixed>                                                                 $move
     * @param array{result: string, skip: string|null, otherRun: int|null, claimedAt: string, attempts: int, identity: list<string|null>} $claim
     */
    private function switch(VpnService $service, VpnInbound $target, Plan $plan, array $move, array $claim, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $driver = $this->drivers->resolve($service->getPanel());
        if (!$driver instanceof InboundMoveDriverInterface) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_NOT_MODERN);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText(InboundMovePlanner::SKIP_NOT_MODERN));
        }
        if ($this->db()->isTransactionActive()) {
            // Never a panel call under someone's locks (5.3, as ServiceInboundAttacher::afterSale()).
            throw new \LogicException('An inbound move step must not run inside a transaction.');
        }

        try {
            $attach = $driver->attachForMove((string) $service->getRemoteId(), $target, $service->getPanel(), false);
        } catch (\Throwable $e) {
            // Not a panel answer (the driver turns those into a result): stored masked, retried like any failure, and
            // the pass goes on with the next service. Whether an attach was sent is unknown: the next try reads first.
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, 'Panel step failed: '.$e->getMessage(), 255, $this->planner->panelSecrets($service->getPanel())));
            $this->logger->warning('inbound_move_panel_step_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'error' => $text]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, false);
        }
        $sent = InboundMoveAttachResult::ATTACHED === $attach->outcome
            || (InboundMoveAttachResult::FAILED === $attach->outcome && in_array($attach->reason, [InboundMoveAttachResult::FAILED_NOT_ATTACHED, InboundMoveAttachResult::FAILED_NOT_CONFIRMED], true));
        if (InboundMoveAttachResult::REFUSED === $attach->outcome) {
            $reason = match ($attach->reason) {
                InboundMoveAttachResult::REFUSED_NOT_MODERN => InboundMovePlanner::SKIP_NOT_MODERN,
                InboundMoveAttachResult::REFUSED_INVALID_TARGET => InboundMovePlanner::SKIP_OTHER_PANEL,
                default => InboundMovePlanner::SKIP_REMOTE_ID,
            };
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_SKIPPED, $reason);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText($reason));
        }
        if (!$attach->onTarget()) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, (string) $attach->message, 255, $this->planner->panelSecrets($service->getPanel())));
            $this->logger->warning('inbound_move_attach_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'reason' => $attach->reason]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, !$attach->panelUnreadable, $sent);
        }

        $written = $this->write($service, $target, $plan, $move, $claim, $attach->inboundIds ?? []);
        if (true !== $written) {
            if (InboundMoveItem::IDENTITY_CHANGED === $written) {
                $this->logger->warning('inbound_move_identity_changed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId]);

                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::IDENTITY_CHANGED, 'the client\'s credentials changed meanwhile (a link reset?): nothing written, picked again', true, $sent);
            }
            if (InboundMoveItem::SKIPPED === $written) {
                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText(InboundMovePlanner::SKIP_REMOTE_ID), true, $sent);
            }

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'the claim was taken over meanwhile: nothing written', true, $sent);
        }
        $this->logger->info('inbound_move_switched', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'from_inbound_id' => $move['from_inbound_id'], 'to_inbound_id' => $target->getId(), 'attach_sent' => $sent]);

        $refresh = $this->refreshLinks($service);

        return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SWITCHED, ($sent ? 'attached and switched' : 'already on the target: switched without an attach').'; links '.self::refreshText($refresh), PanelLinkRefresh::FAILED !== $refresh->outcome, $sent, $refresh->outcome);
    }

    /**
     * Step 1 (see the class comment).
     *
     * @param array<string, mixed> $move
     *
     * @return array{result: string, skip: string|null, otherRun: int|null, claimedAt: string, attempts: int, identity: list<string|null>}
     */
    private function claim(array $move, ?VpnInbound $target, InboundMoveScope $scope, int $runId): array
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $move['service_id'];
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');

        return $this->transactional(function (Connection $db) use ($moveId, $serviceId, $target, $scope, $runId, $now, $at): array {
            $service = $db->fetchAssociative('SELECT id, status, inbound_id, panel_id, remote_id, client_uuid, sub_id, order_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            $none = ['result' => self::NOT_DUE, 'skip' => null, 'otherRun' => null, 'claimedAt' => $at, 'attempts' => 0, 'identity' => []];
            if (false === $service || false === $row || !$this->due($row, $now)) {
                return $none;
            }
            // A plain read (no lock): the order row is never locked by a move (5.3, 5.7).
            $service['order_type'] = null === $service['order_id'] ? null : $db->fetchOne('SELECT type FROM `order` WHERE id = ?', [(int) $service['order_id']]);

            [$skip, $otherRun] = [null, null];
            $inboundId = null === $service['inbound_id'] ? null : (int) $service['inbound_id'];
            $fromId = null === $row['from_inbound_id'] ? null : (int) $row['from_inbound_id'];
            if (!$target instanceof VpnInbound) {
                $skip = InboundMovePlanner::SKIP_TARGET_GONE;
            } elseif ($inboundId === (int) $target->getId()) {
                $skip = InboundMovePlanner::SKIP_ALREADY_ON_TARGET;
            } elseif ($inboundId !== $fromId || (null === $inboundId && InboundMovePlanner::remoteInboundOf($service) !== $row['from_remote_inbound_id'])) {
                $skip = InboundMovePlanner::SKIP_INBOUND_CHANGED;
            } elseif (1 !== (int) $db->fetchOne('SELECT is_active FROM vpn_inbound WHERE id = ?', [(int) $target->getId()])) {
                $skip = InboundMovePlanner::SKIP_TARGET_INACTIVE;
            } else {
                [$skip, $otherRun] = $this->planner->skipReason($service, $target, $scope, $runId);
            }
            if (null !== $skip) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, $skip, $at, $moveId],
                );

                return ['skip' => $skip, 'otherRun' => $otherRun] + $none;
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_ATTACHING, $at, $at, $moveId],
            );

            return [
                'result' => self::CLAIMED,
                'skip' => null,
                'otherRun' => null,
                'claimedAt' => $at,
                'attempts' => (int) $row['attempts'] + 1,
                'identity' => self::identity($service),
            ];
        });
    }

    /**
     * Step 3 (see the class comment).
     *
     * @param array<string, mixed>                       $move
     * @param array{claimedAt: string, identity: list<string|null>} $claim
     * @param list<int>                                  $onPanel the client's remote inbound ids as read after step 2
     *
     * @return true|string true when switched; InboundMoveItem::IDENTITY_CHANGED or BUSY when nothing was written
     */
    private function write(VpnService $service, VpnInbound $target, Plan $plan, array $move, array $claim, array $onPanel): bool|string
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $fromId = null === $move['from_inbound_id'] ? null : (int) $move['from_inbound_id'];
        $fromRemote = null === $move['from_remote_inbound_id'] ? null : (string) $move['from_remote_inbound_id'];
        $keepFrom = null !== $fromId && in_array($fromId, $this->planner->planAdditionalIds((int) $plan->getId()), true);

        $result = $this->transactional(function (Connection $db) use ($serviceId, $moveId, $target, $claim, $fromId, $fromRemote, $keepFrom, $onPanel): bool|string {
            $now = self::now();
            $current = $db->fetchAssociative('SELECT id, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT state, claimed_at FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            if (false === $current || false === $row || ServiceInboundMove::STATE_ATTACHING !== $row['state'] || (string) $row['claimed_at'] !== $claim['claimedAt']) {
                return InboundMoveItem::BUSY;
            }
            if (self::identity($current) !== $claim['identity']) {
                // A link reset (or anything else) changed the client meanwhile: nothing written, picked again.
                $db->executeStatement('UPDATE service_inbound_move SET state = ?, claimed_at = NULL, updated_at = ? WHERE id = ?', [ServiceInboundMove::STATE_PLANNED, $now, $moveId]);

                return InboundMoveItem::IDENTITY_CHANGED;
            }
            $remoteId = $this->remoteIdParser->withInbound((string) $current['remote_id'], (int) $target->getId(), $target->getRemoteInboundId());
            if (null === $remoteId) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_REMOTE_ID, $now, $moveId],
                );

                return InboundMoveItem::SKIPPED;
            }
            $db->executeStatement(
                'UPDATE vpn_service SET inbound_id = ?, remote_id = ?, inbound_changed_at = ? WHERE id = ?',
                [$target->getId(), $remoteId, $now, $serviceId],
            );
            // B is the primary now: never an additional row of its own.
            $db->executeStatement('DELETE FROM vpn_service_inbound WHERE service_id = ? AND inbound_id = ?', [$serviceId, $target->getId()]);
            $hasFrom = [] !== array_filter($rows, static fn (array $r): bool => (int) $r['inbound_id'] === $fromId);
            if ($keepFrom && !$hasFrom && null !== $fromRemote) {
                // A stays one of the plan's additional inbounds: it is now an additional row of the service. Attached when
                // the read after step 2 listed it; otherwise failed and due, for the scheduled attach (never a lie).
                $listed = in_array((int) $fromRemote, $onPanel, true);
                $db->insert('vpn_service_inbound', [
                    'service_id' => $serviceId,
                    'inbound_id' => $fromId,
                    'remote_inbound_id' => $fromRemote,
                    'status' => $listed ? VpnServiceInbound::STATUS_ATTACHED : VpnServiceInbound::STATUS_FAILED,
                    'attempts' => 0,
                    'last_error' => $listed ? null : 'not on the panel when the service was moved to another inbound',
                    'attached_at' => $listed ? $now : null,
                    'updated_at' => $now,
                    'checked_at' => $now,
                    'reattach_count' => 0,
                ]);
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, detach_after = NULL, next_attempt_at = NULL, last_error = NULL, claimed_at = NULL, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_SWITCHED, $now, $moveId],
            );

            return true;
        });
        // Written with SQL: what this entity manager holds is stale now.
        $this->entityManager->refresh($service);

        return $result;
    }

    /**
     * Step 4: the links of the client as the panel renders them now (PanelLinkSnapshot's rules; no lock of ours held).
     */
    private function refreshLinks(VpnService $service): PanelLinkRefresh
    {
        $this->entityManager->refresh($service);

        return $this->panelLinkSnapshot->refresh($service, self::SOURCE, true, false);
    }

    /**
     * A failed attempt: back-off (MultiInboundSettings::nextAttemptAt(); NULL once the attempts are used up: given up),
     * only while the move still holds this claim. The error is stored masked.
     *
     * @return string the masked error
     */
    private function fail(int $serviceId, int $moveId, string $claimedAt, int $attempts, string $error): string
    {
        $error = VpnServiceInbound::safeError($error);
        $next = $this->retries->nextAttemptAt($attempts, new \DateTimeImmutable())?->format('Y-m-d H:i:s');
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $error, $next): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, last_error = ?, next_attempt_at = ?, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [ServiceInboundMove::STATE_FAILED, $error, $next, self::now(), $moveId, ServiceInboundMove::STATE_ATTACHING, $claimedAt],
            );
        });

        return null === $next ? $error.' (attempts used up: given up)' : $error.' (next try after '.$next.')';
    }

    /** A claimed move ends without a panel write: skipped with the reason (same lock order, only under this claim). */
    private function settleClaim(int $serviceId, int $moveId, string $claimedAt, string $state, string $reason): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $state, $reason): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [$state, $reason, self::now(), $moveId, ServiceInboundMove::STATE_ATTACHING, $claimedAt],
            );
        });
    }

    /**
     * Whether a move row (as locked) may be claimed now: planned; attaching with a stale claim (a process died); failed
     * and due with attempts left.
     *
     * @param array<string, mixed> $row
     */
    private function due(array $row, \DateTimeImmutable $now): bool
    {
        $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');

        return match ($row['state']) {
            ServiceInboundMove::STATE_PLANNED => true,
            ServiceInboundMove::STATE_ATTACHING => null === $row['claimed_at'] || (string) $row['claimed_at'] < $stale,
            ServiceInboundMove::STATE_FAILED => null !== $row['next_attempt_at'] && (string) $row['next_attempt_at'] <= $now->format('Y-m-d H:i:s') && (int) $row['attempts'] < $this->retries->maxAttempts(),
            default => false,
        };
    }

    /**
     * The run's moves this pass looks at, after $afterId: due for a claim (see due()), or switched with links not
     * refreshed since the switch.
     *
     * @return list<int>
     */
    private function dueMoveIds(int $runId, int $afterId, int $limit): array
    {
        $now = new \DateTimeImmutable();

        return array_map('intval', $this->db()->fetchFirstColumn(
            sprintf(
                "SELECT m.id FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id
                 WHERE m.run_id = :run AND m.id > :after AND (
                   m.state = 'planned'
                   OR (m.state = 'attaching' AND (m.claimed_at IS NULL OR m.claimed_at < :stale))
                   OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL AND m.next_attempt_at <= :now AND m.attempts < :max)
                   OR (m.state = 'switched' AND %s))
                 ORDER BY m.id LIMIT %d",
                self::LINKS_PENDING_SQL,
                max(1, $limit),
            ),
            ['run' => $runId, 'after' => $afterId, 'stale' => $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s'), 'max' => $this->retries->maxAttempts()],
        ));
    }

    /** A switched service whose links were not refreshed since (the display guard's condition, 5.5). */
    private const LINKS_PENDING_SQL = 's.inbound_changed_at IS NOT NULL AND (s.last_access_info_synced_at IS NULL OR s.last_access_info_synced_at < s.inbound_changed_at)';

    /** Counters, the given-up alerts (one per service) and what is pending, after a pass. */
    private function finishPass(int $runId, InboundMovePass $pass): void
    {
        $this->planner->refreshCounts($runId);
        $pass->alertsSent += $this->alertGivenUp($runId);
        $pass->snapshotsPending = (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND m.state = ? AND '.self::LINKS_PENDING_SQL,
            [$runId, ServiceInboundMove::STATE_SWITCHED],
        );
        $run = $this->db()->fetchAssociative('SELECT status, pause_reason, planned_count, done_count, failed_count, skipped_count, remaining_count FROM inbound_move_run WHERE id = ?', [$runId]) ?: [];
        $pass->runStatus = (string) ($run['status'] ?? '');
        $pass->pauseReason = null === ($run['pause_reason'] ?? null) ? null : (string) $run['pause_reason'];
        $pass->counts = [
            'planned' => (int) ($run['planned_count'] ?? 0),
            'done' => (int) ($run['done_count'] ?? 0),
            'failed' => (int) ($run['failed_count'] ?? 0),
            'skipped' => (int) ($run['skipped_count'] ?? 0),
            'remaining' => (int) ($run['remaining_count'] ?? 0),
        ];
    }

    /**
     * The run is done when nothing in it can still change: no planned or attaching move, no failed move with an attempt
     * left, no switched service waiting for its links. The transition is one conditional UPDATE, so with two runners
     * only one finishes it: the finished audit entry and the alert (when moves failed) are sent once.
     */
    private function finishRun(int $runId, int $planId, InboundMovePass $pass): void
    {
        $finished = $this->db()->executeStatement(
            sprintf(
                "UPDATE inbound_move_run SET status = ?, open_plan_id = NULL, finished_at = ? WHERE id = ? AND status = ? AND NOT EXISTS (
                   SELECT 1 FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND (
                     m.state IN ('planned', 'attaching')
                     OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL AND m.attempts < ?)
                     OR (m.state = 'switched' AND %s)))",
                self::LINKS_PENDING_SQL,
            ),
            [InboundMoveRun::STATUS_DONE, self::now(), $runId, InboundMoveRun::STATUS_RUNNING, $runId, $this->retries->maxAttempts()],
        );
        if (1 !== $finished) {
            return;
        }
        $this->finishPass($runId, $pass);
        $this->auditLogger->record('plan.inbounds_apply_finished', 'plan', $planId, ['run_id' => $runId] + $pass->counts);
        $this->logger->info('inbound_move_run_finished', ['run_id' => $runId, 'plan_id' => $planId] + $pass->counts);
        if ($pass->counts['failed'] > 0 && $this->alerts->moveRunEnded($runId, $planId, $pass->counts, null)) {
            ++$pass->alertsSent;
        }
    }

    /** running → paused (5.4), with one alert from the process that paused it. */
    private function pause(int $runId, int $planId, string $reason, InboundMovePass $pass): void
    {
        $paused = $this->db()->executeStatement(
            'UPDATE inbound_move_run SET status = ?, pause_reason = ? WHERE id = ? AND status = ?',
            [InboundMoveRun::STATUS_PAUSED, $reason, $runId, InboundMoveRun::STATUS_RUNNING],
        );
        if (1 === $paused) {
            $this->planner->refreshCounts($runId);
            $this->logger->error('inbound_move_run_paused', ['run_id' => $runId, 'plan_id' => $planId, 'reason' => $reason]);
            $counts = $this->db()->fetchAssociative('SELECT planned_count AS planned, done_count AS done, failed_count AS failed, skipped_count AS skipped, remaining_count AS remaining FROM inbound_move_run WHERE id = ?', [$runId]) ?: [];
            if ($this->alerts->moveRunEnded($runId, $planId, array_map('intval', $counts), $reason)) {
                ++$pass->alertsSent;
            }
        }
    }

    /** One alert per move whose attempts are used up (claimed on the move row: never twice). */
    private function alertGivenUp(int $runId): int
    {
        $sent = 0;
        foreach ($this->db()->fetchFirstColumn(
            'SELECT id FROM service_inbound_move WHERE run_id = ? AND state = ? AND next_attempt_at IS NULL AND attempts >= ? AND alert_state IS NULL ORDER BY id',
            [$runId, ServiceInboundMove::STATE_FAILED, $this->retries->maxAttempts()],
        ) as $moveId) {
            $sent += $this->alerts->moveGaveUp((int) $moveId) ? 1 : 0;
        }

        return $sent;
    }

    private function count(InboundMovePass $pass, InboundMoveItem $item): void
    {
        switch ($item->outcome) {
            case InboundMoveItem::SWITCHED:
                ++$pass->switched;
                $pass->alreadyOnTarget += $item->attachSent ? 0 : 1;
                break;
            case InboundMoveItem::FAILED:
                ++$pass->failed;
                break;
            case InboundMoveItem::SKIPPED:
                ++$pass->skipped;
                break;
            case InboundMoveItem::SNAPSHOT:
                $pass->snapshotsRefreshed += in_array($item->snapshot, [PanelLinkRefresh::UPDATED, PanelLinkRefresh::UNCHANGED, PanelLinkRefresh::CHANGED_MEANWHILE], true) ? 1 : 0;
                break;
        }
    }

    /**
     * @param array<string, mixed> $move
     *
     * @return array{string, string} the old and the target inbound, by title and id
     */
    private function titles(array $move): array
    {
        $title = function (mixed $id, mixed $remote): string {
            if (null === $id) {
                return sprintf('(deleted inbound, panel id %s)', null === $remote ? '?' : (string) $remote);
            }
            $title = $this->db()->fetchOne('SELECT title FROM vpn_inbound WHERE id = ?', [(int) $id]);

            return sprintf('"%s" (#%d)', mb_substr((string) $title, 0, 60), (int) $id);
        };

        return [$title($move['from_inbound_id'], $move['from_remote_inbound_id']), $title($move['to_inbound_id'], $move['to_remote_inbound_id'])];
    }

    private static function refreshText(PanelLinkRefresh $refresh): string
    {
        return match ($refresh->outcome) {
            PanelLinkRefresh::UPDATED, PanelLinkRefresh::UNCHANGED => sprintf('refreshed from the panel (%d link(s))', $refresh->snapshotCount()),
            PanelLinkRefresh::KEPT_EMPTY_ANSWER => 'not refreshed: the panel answered with no links, the stored ones were kept (retried next pass)',
            PanelLinkRefresh::EMPTY => 'not refreshed: the panel has no links for this client (retried next pass)',
            PanelLinkRefresh::CHANGED_MEANWHILE => 'refreshed after a credential change meanwhile',
            default => 'not refreshed: the panel could not be read (retried next pass)',
        };
    }

    /**
     * @param array<string, mixed> $service
     *
     * @return list<string|null> inbound, UUID, subId, remote id: what the write requires unchanged
     */
    private static function identity(array $service): array
    {
        return [
            null === $service['inbound_id'] ? null : (string) $service['inbound_id'],
            (string) $service['client_uuid'],
            (string) $service['sub_id'],
            (string) $service['remote_id'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * A short READ COMMITTED transaction (as PanelLinkSnapshot's write).
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        $db = $this->db();
        if (0 === $db->getTransactionNestingLevel()) {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
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
