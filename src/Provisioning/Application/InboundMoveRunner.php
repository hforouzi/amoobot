<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\Plan;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\InboundDetachResult;
use App\Provisioning\Domain\Dto\InboundMoveAttachResult;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\InboundDetachDriverInterface;
use App\Provisioning\Domain\InboundMoveDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
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
 * A primary move run (docs/design/plan-inbound-change.md 4.3, 5.3, 5.4; PR P4 phase 1, PR P6 phase 2 and undo): each
 * planned service of the run is put on the target B as well, switched to it locally, its links refreshed (phase 1);
 * after the run's grace period the old inbound A is removed from its client (phase 2, "retire").
 *
 * Phase 1, per service, in this order:
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
 *    additional row for A when A stays one of the plan's additional inbounds, and the move "switched" with
 *    detach_after = now + the run's grace_hours (NULL with keep_old, or when A stays an additional inbound: never
 *    retired automatically). If the identity changed nothing is written and the move goes back to "planned" (picked
 *    again at once, once).
 * 4. PanelLinkSnapshot::refresh() with its own lock and rules (the panel's subLinks only; an empty answer keeps the old
 *    links). A moved service whose links were not refreshed (inbound_changed_at after last_access_info_synced_at) is
 *    refreshed by the next pass.
 *
 * Phase 2 (4.3 step 5), a switched move whose detach_after has passed:
 * 5. Claim as in 1, with the check inbound_id = B: "detaching". Then, with no transaction and no lock,
 *    InboundDetachDriverInterface::detachInbounds() (GET; its guards refuse to detach A when it is the client's only
 *    inbound or the local primary; detach [A]; GET). B missing on the panel: B is attached again FIRST
 *    (attachForMove() with A as the expected inbound) and the retire is picked again at once, once: A is never detached
 *    without B listed. A client on neither A nor B: failed, nothing sent. A detach the panel refuses because A was
 *    deleted there counts as done when inbounds/list confirms A is gone. Done: the move "done", detached_at, and
 *    inbound_changed_at = now (the display guard hides A's stored links until the refresh), then the refresh.
 *    Failures back off and give up like phase 1 (the move "failed", detach_after kept).
 *
 * Each step of a service shares one PanelCallBudget of InboundMovePlanner::STEP_BUDGET_SECONDS (at most 4 calls). Every
 * panel call is made with recordHealth=false: a bad run never marks the panel degraded or stops its sales. No order,
 * payment, wallet, user or reseller row is ever locked or written; the run row is changed only by autocommit UPDATEs,
 * never while a service is locked. After MAX_READ_FAILURES_IN_A_ROW services in a row whose panel could not be read, the
 * run pauses itself (one alert); it is resumed only by running the command again with --resume. A run paused or
 * cancelled meanwhile (InboundMoveControls) is noticed between two services: the service in hand is finished, no other
 * is taken.
 *
 * undo() (5.4): moves switched and not yet retired go back to A (A attached again if missing, the service on A, B
 * detached unless B is one of the plan's additional inbounds, the links refreshed: "undone"). Retired ones cannot be.
 *
 * A kind=add run (docs/design/plan-inbound-add-remove.md 5.1, 7; PR P11b) has its own steps, never the pending rows of
 * the attach timer (a sale's way of adding an inbound): each service's client is put on the target C, its row is written
 * "attached" under the same locks, and its links are refreshed. The service's primary, remote id, inbound_changed_at and
 * every other column of vpn_service stay as they are. The claim also takes C's failed or stale row the way
 * ServiceInboundAttacher claims it, so a "Retry attach" and a run never both call the panel for one row. It needs
 * plans.multi_inbound_enabled at the start of each pass, and pauses when the plan no longer sells C and work has not
 * started (Q7). Its moves have no detach_after: never a retire. undo() takes C off again (see undoAddOne()); a client
 * that had C before the run (already_on_panel) is left alone.
 */
final class InboundMoveRunner
{
    public const SOURCE = InboundMovePlanner::SOURCE;
    public const MAX_READ_FAILURES_IN_A_ROW = InboundAttachScheduler::MAX_CONSECUTIVE_FAILURES;
    public const PAUSE_PANEL = 'panel not answering: %d failed panel reads in a row';
    public const OPTIONS_UNREADABLE = 'the panel\'s inbound options could not be read (protocol rules not checked): not moved yet.';
    public const UNDO_IN_PROGRESS = 'undo in progress (--undo)';
    public const UNDO_INCOMPLETE = 'undo incomplete: %d service(s) not put back yet (run --undo again)';
    public const NEITHER_INBOUND = 'Not retired: '.InboundMoveAttachResult::NOT_ON_EXPECTED_TEXT;
    public const NOT_ON_PRIMARY = 'Not added: the client is not on its primary inbound on the panel (changed by hand?): nothing sent.';
    public const UNDO_ADD_HOLD = 'being removed by undo of run #%d';
    public const PLAN_DROPPED = 'the plan no longer sells inbound #%d: put it back, then --resume; or --cancel';
    public const NOT_LATEST = 'Run #%d is not the plan\'s latest run: undo the later run first, so that its work is not taken away under it.';

    private const CLAIMED = 'claimed';
    /** The claim found a reason not to go on (skipped, or an error for --undo): written under the locks, reported. */
    private const SETTLED = 'settled';
    private const NOT_DUE = 'not_due';
    private const KEPT = 'kept';

    /**
     * On alias m: a kind=add move on the panel with its links not refreshed yet (docs/design/plan-inbound-add-remove.md
     * 3.2). It is due and holds its run open; an add never writes inbound_changed_at, so LINKS_PENDING_SQL cannot see it.
     * A primary move in "switched" with no detach_after is a keep-old move, which is finished.
     */
    public const ADD_LINKS_DUE_SQL = "(m.state = 'switched' AND EXISTS (SELECT 1 FROM inbound_move_run add_run WHERE add_run.id = m.run_id AND add_run.kind = 'add'))";

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
     * One pass over the run's due moves (phase 1 and due retirements), in order of move id, in batches with a pause
     * between them.
     *
     * @param \Closure(InboundMoveItem): void|null $onItem      called after each service (console)
     * @param bool                                 $leavePaused the scheduled task: a paused run is not resumed (only an
     *                                                          admin's --resume does that, the default)
     * @param float|null                           $stopAt      the scheduled task: microtime(true) after which no other
     *                                                          service is started; the run stays as it is
     */
    public function process(int $runId, ?int $batchSize = null, ?int $pauseSeconds = null, ?\Closure $onItem = null, bool $leavePaused = false, ?float $stopAt = null): InboundMovePass
    {
        if (!$this->settings->enabled()) {
            return new InboundMovePass(InboundMovePass::SWITCHED_OFF, sprintf('Moving services is switched off (%s): nothing was done.', InboundMoveSettings::ENABLED));
        }
        if ($this->db()->isTransactionActive()) {
            return new InboundMovePass(InboundMovePass::REFUSED, 'Refused inside a transaction: panel calls are never made under someone\'s locks.');
        }
        $run = $this->db()->fetchAssociative('SELECT id, plan_id, kind, status, target_inbound_id, keep_old, grace_hours FROM inbound_move_run WHERE id = ?', [$runId]);
        $startable = $leavePaused ? [InboundMoveRun::STATUS_PLANNED, InboundMoveRun::STATUS_RUNNING] : InboundMoveRun::OPEN_STATUSES;
        if (false === $run || !in_array($run['status'], $startable, true) || !in_array($run['kind'], InboundMoveRun::RUNNABLE_KINDS, true)) {
            return new InboundMovePass(InboundMovePass::NOT_OPEN, false === $run ? sprintf('There is no run #%d.', $runId) : sprintf('Run #%d is %s: nothing to do.', $runId, (string) $run['status']));
        }
        $add = InboundMoveRun::KIND_ADD === $run['kind'];
        if ($add && !$this->retries->enabled()) {
            return new InboundMovePass(InboundMovePass::SWITCHED_OFF, sprintf('Multi-inbound plans are switched off (%s): nothing was done.', MultiInboundSettings::ENABLED));
        }
        $planId = (int) $run['plan_id'];
        $graceHours = 1 === (int) $run['keep_old'] ? null : max(0, (int) $run['grace_hours']);
        $this->db()->executeStatement(
            sprintf('UPDATE inbound_move_run SET status = ?, open_plan_id = plan_id, pause_reason = NULL, started_at = COALESCE(started_at, ?) WHERE id = ? AND status IN (%s)', implode(', ', array_fill(0, count($startable), '?'))),
            [InboundMoveRun::STATUS_RUNNING, self::now(), $runId, ...$startable],
        );
        $batchSize = max(1, min(InboundMoveSettings::MAX_BATCH_SIZE, $batchSize ?? $this->settings->batchSize()));
        $pauseSeconds = max(0, min(InboundMoveSettings::MAX_PAUSE_SECONDS, $pauseSeconds ?? $this->settings->pauseSeconds()));
        $scope = InboundMoveScope::fromArray(json_decode((string) $this->db()->fetchOne('SELECT scope FROM inbound_move_run WHERE id = ?', [$runId]), true) ?: []);
        $this->planner->forgetPanelReads();

        $pass = new InboundMovePass();
        $pass->kind = (string) $run['kind'];
        if ($add && $this->planDropped($runId, $planId, $run['target_inbound_id'])) {
            $this->pause($runId, $planId, sprintf(self::PLAN_DROPPED, (int) $run['target_inbound_id']), $pass);
            $pass->paused = true;
            $this->finishPass($runId, $pass);

            return $pass;
        }
        $step = $add
            ? fn (int $moveId): InboundMoveItem => $this->addOne($moveId, $scope, $runId)
            : fn (int $moveId): InboundMoveItem => $this->one($moveId, $planId, $scope, $runId, $graceHours);
        $lastId = 0;
        $readFailures = 0;
        while (true) {
            $ids = $this->dueMoveIds($runId, $lastId, $batchSize);
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $moveId) {
                if (!$this->stillRunning($runId)) {
                    // Paused or cancelled meanwhile (--pause-run, --cancel, --undo): the item in hand was finished.
                    $this->finishPass($runId, $pass);

                    return $pass;
                }
                if (null !== $stopAt && microtime(true) >= $stopAt) {
                    $pass->outOfTime = true;
                    $this->finishPass($runId, $pass);

                    return $pass;
                }
                $lastId = $moveId;
                $item = $step($moveId);
                if (InboundMoveItem::IDENTITY_CHANGED === $item->outcome) {
                    // Picked again at once (4.3 step 3), for the credentials the service has now.
                    $item = $step($moveId);
                }
                if (InboundMoveItem::REATTACHED === $item->outcome || (InboundMoveItem::SWITCHED === $item->outcome && 0 === $graceHours)) {
                    // B attached again (retire) or a run without grace: the retire is a step of its own, at once, once.
                    $this->count($pass, $item);
                    if (null !== $onItem) {
                        $onItem($item);
                    }
                    $readFailures = 0;
                    $item = $this->one($moveId, $planId, $scope, $runId, $graceHours);
                    if (InboundMoveItem::BUSY === $item->outcome && 0 === $graceHours) {
                        // Switched, but the retire was not due (or taken) after all: nothing more to say.
                        continue;
                    }
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
                    $pass->paused = true;
                    $this->finishPass($runId, $pass);

                    return $pass;
                }
            }
            // Every service was written on its own; the next batch reads fresh rows.
            $this->entityManager->clear();
            if (count($ids) === $batchSize && $pauseSeconds > 0) {
                sleep(null === $stopAt ? $pauseSeconds : min($pauseSeconds, max(0, (int) ceil($stopAt - microtime(true)))));
            }
        }
        $this->finishPass($runId, $pass);
        $this->finishRun($runId, $planId, $pass);

        return $pass;
    }

    /** The moves of a run that a pass would look at now (--dry-run of the scheduled task; at most 1000). */
    public function dueCount(int $runId): int
    {
        $kind = $this->db()->fetchOne('SELECT kind FROM inbound_move_run WHERE id = ?', [$runId]);
        if (!in_array($kind, InboundMoveRun::RUNNABLE_KINDS, true) || (InboundMoveRun::KIND_ADD === $kind && !$this->retries->enabled())) {
            return 0;
        }

        return count($this->dueMoveIds($runId, 0, 1000));
    }

    /**
     * --undo (5.4): see the class comment. Moves not started yet (planned, or failed in phase 1 and waiting for a retry)
     * are cancelled first; an open run is paused while it runs and cancelled when nothing is left to put back.
     *
     * @param \Closure(InboundMoveItem): void|null $onItem called after each service (console)
     */
    public function undo(int $runId, ?\Closure $onItem = null): InboundMovePass
    {
        if (!$this->settings->enabled()) {
            return new InboundMovePass(InboundMovePass::SWITCHED_OFF, sprintf('Moving services is switched off (%s): nothing was done.', InboundMoveSettings::ENABLED));
        }
        if ($this->db()->isTransactionActive()) {
            return new InboundMovePass(InboundMovePass::REFUSED, 'Refused inside a transaction: panel calls are never made under someone\'s locks.');
        }
        $run = $this->db()->fetchAssociative('SELECT id, plan_id, kind, status FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $run || !in_array($run['kind'], InboundMoveRun::RUNNABLE_KINDS, true)) {
            return new InboundMovePass(InboundMovePass::NOT_OPEN, sprintf('There is no run #%d to undo.', $runId));
        }
        $planId = (int) $run['plan_id'];
        $add = InboundMoveRun::KIND_ADD === $run['kind'];
        // A primary run after a primary run keeps its rule (a service moved again is skipped); an add or remove in between
        // changes what the earlier run would put back (docs/design/plan-inbound-add-remove.md 7).
        if (false !== $this->db()->fetchOne("SELECT id FROM inbound_move_run WHERE plan_id = ? AND id > ? AND (kind <> 'primary' OR ? <> 'primary') LIMIT 1", [$planId, $runId, $run['kind']])) {
            return new InboundMovePass(InboundMovePass::REFUSED, sprintf(self::NOT_LATEST, $runId));
        }
        $open = in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true);
        if ($open) {
            // No runner takes another service of this run meanwhile (it stops after the service in hand).
            $this->db()->executeStatement(
                'UPDATE inbound_move_run SET status = ?, pause_reason = ? WHERE id = ? AND status IN (?, ?, ?)',
                [InboundMoveRun::STATUS_PAUSED, self::UNDO_IN_PROGRESS, $runId, ...InboundMoveRun::OPEN_STATUSES],
            );
        }
        $pass = new InboundMovePass();
        $pass->kind = (string) $run['kind'];
        $pass->cancelled = $this->cancelNotStarted($runId, $add);

        $lastId = 0;
        $readFailures = 0;
        $pausedForPanel = null;
        while (null === $pausedForPanel) {
            $ids = $this->undoMoveIds($runId, $lastId, $this->settings->batchSize(), $add);
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $moveId) {
                $lastId = $moveId;
                $item = $add ? $this->undoAddOne($moveId, $runId) : $this->undoOne($moveId, $planId);
                if (InboundMoveItem::IDENTITY_CHANGED === $item->outcome) {
                    $item = $add ? $this->undoAddOne($moveId, $runId) : $this->undoOne($moveId, $planId);
                }
                match ($item->outcome) {
                    InboundMoveItem::UNDONE => ++$pass->undone,
                    InboundMoveItem::FAILED => ++$pass->failed,
                    InboundMoveItem::SKIPPED => ++$pass->skipped,
                    default => ++$pass->busy,
                };
                if (null !== $onItem) {
                    $onItem($item);
                }
                if (false === $item->panelRead) {
                    ++$readFailures;
                } elseif (true === $item->panelRead) {
                    $readFailures = 0;
                }
                if ($readFailures >= self::MAX_READ_FAILURES_IN_A_ROW) {
                    $pausedForPanel = sprintf(self::PAUSE_PANEL, $readFailures);
                    break;
                }
            }
            $this->entityManager->clear();
        }

        $stale = (new \DateTimeImmutable())->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
        if ($add) {
            // An add removes nothing, so nothing is "already retired"; these clients had C before the run.
            $pass->leftAlone = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND skip_reason = ?', [$runId, InboundMovePlanner::ALREADY_ON_PANEL]);
        } else {
            $pass->alreadyRetired = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state = ?', [$runId, ServiceInboundMove::STATE_DONE]);
        }
        $inFlight = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state IN ('attaching', 'detaching') AND claimed_at IS NOT NULL AND claimed_at >= ?",
            [$runId, $stale],
        );
        $pass->busy += $inFlight;
        $left = count($this->undoMoveIds($runId, 0, 1000, $add));
        $reason = $pausedForPanel;
        if ($open) {
            if (0 === $left && 0 === $inFlight && null === $pausedForPanel) {
                $this->db()->executeStatement(
                    'UPDATE inbound_move_run SET status = ?, open_plan_id = NULL, pause_reason = NULL, finished_at = ? WHERE id = ? AND status = ?',
                    [InboundMoveRun::STATUS_CANCELLED, self::now(), $runId, InboundMoveRun::STATUS_PAUSED],
                );
            } else {
                $reason ??= sprintf(self::UNDO_INCOMPLETE, $left + $inFlight);
                $this->db()->executeStatement('UPDATE inbound_move_run SET pause_reason = ? WHERE id = ? AND status = ?', [$reason, $runId, InboundMoveRun::STATUS_PAUSED]);
            }
        }
        $this->finishPass($runId, $pass);
        $this->auditLogger->record('plan.inbounds_apply_undone', 'plan', $planId, [
            'run_id' => $runId,
            'undone' => $pass->undone,
            'failed' => $pass->failed,
            'skipped' => $pass->skipped,
            'busy' => $pass->busy,
            'cancelled' => $pass->cancelled,
            'already_retired' => $pass->alreadyRetired,
        ] + $this->planner->runDetails($runId) + ($add ? ['left_alone' => $pass->leftAlone] : []));
        $this->logger->info('inbound_move_run_undone', ['run_id' => $runId, 'plan_id' => $planId, 'undone' => $pass->undone, 'failed' => $pass->failed, 'already_retired' => $pass->alreadyRetired]);
        if (null !== $pausedForPanel) {
            $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $planId, ['run_id' => $runId, 'by' => 'automatic'] + $this->planner->runDetails($runId) + $pass->counts);
        }
        if (($pass->failed > 0 || null !== $pausedForPanel) && $this->alerts->moveRunEnded($runId, $planId, $pass->counts, $reason ?? sprintf(self::UNDO_INCOMPLETE, $pass->failed))) {
            ++$pass->alertsSent;
        }

        return $pass;
    }

    /** One move: a pending link refresh only (switched or done), a due retire (4.3 step 5), or phase 1 (steps 1-4). */
    private function one(int $moveId, int $planId, InboundMoveScope $scope, int $runId, ?int $graceHours): InboundMoveItem
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
        if (self::isRetire($move, self::now())) {
            return $this->retireOne($service, $move, $from, $to);
        }
        if (in_array($move['state'], [ServiceInboundMove::STATE_SWITCHED, ServiceInboundMove::STATE_DONE], true)) {
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
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_PROTOCOL_MIX);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText(InboundMovePlanner::SKIP_PROTOCOL_MIX));
        }
        if (null === $mix) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], self::OPTIONS_UNREADABLE);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, false);
        }

        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->switch($service, $target, $plan, $move, $claim, $from, $to, $graceHours));
    }

    /**
     * Steps 2-4 for a claimed move.
     *
     * @param array<string, mixed>                                                                 $move
     * @param array{result: string, skip: string|null, otherRun: int|null, claimedAt: string, attempts: int, identity: list<string|null>} $claim
     */
    private function switch(VpnService $service, VpnInbound $target, Plan $plan, array $move, array $claim, string $from, string $to, ?int $graceHours): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $driver = $this->drivers->resolve($service->getPanel());
        if (!$driver instanceof InboundMoveDriverInterface) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_NOT_MODERN);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText(InboundMovePlanner::SKIP_NOT_MODERN));
        }
        self::assertNoTransaction($this->db());

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
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_SKIPPED, $reason);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::skipText($reason));
        }
        if (!$attach->onTarget()) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, (string) $attach->message, 255, $this->planner->panelSecrets($service->getPanel())));
            $this->logger->warning('inbound_move_attach_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'reason' => $attach->reason]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, !$attach->panelUnreadable, $sent);
        }

        $written = $this->write($service, $target, $plan, $move, $claim, $attach->inboundIds ?? [], $graceHours);
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
     * kind=add, one move: a link refresh still owed (switched), or the attach step. See the class comment.
     */
    private function addOne(int $moveId, InboundMoveScope $scope, int $runId): InboundMoveItem
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
        if (in_array($move['state'], [ServiceInboundMove::STATE_SWITCHED, ServiceInboundMove::STATE_DONE], true)) {
            $refresh = $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): PanelLinkRefresh => $this->refreshLinks($service));
            $this->settleAddLinks($moveId, $refresh);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SNAPSHOT, self::addRefreshText($refresh), PanelLinkRefresh::FAILED !== $refresh->outcome, false, $refresh->outcome);
        }
        if (ServiceInboundMove::STATE_DETACHING === $move['state']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'an undo stopped here: run --undo again');
        }

        $target = null === $move['to_inbound_id'] ? null : $this->entityManager->find(VpnInbound::class, (int) $move['to_inbound_id']);
        $claim = $this->claimAdd($move, $target, $scope, $runId);
        if (self::NOT_DUE === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile, or no longer due');
        }
        if (null !== $claim['skip']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::addSkipText($claim['skip'], $claim['otherRun']));
        }
        \assert($target instanceof VpnInbound);
        $this->entityManager->refresh($service);

        // 4.4 protocol mix: after the claim, outside the transaction (it may read inbounds/options).
        try {
            $mix = $this->planner->addProtocolMix($serviceId, $target);
        } catch (\Throwable) {
            $mix = null;
        }
        if (true === $mix) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_PROTOCOL_MIX, $claim['heldRow']);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::addSkipText(InboundMovePlanner::SKIP_PROTOCOL_MIX));
        }
        if (null === $mix) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], self::OPTIONS_UNREADABLE, ServiceInboundMove::STATE_ATTACHING, $claim['heldRow']);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, false);
        }

        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->attachAdded($service, $target, $move, $claim, $from, $to));
    }

    /**
     * The claim of an add, in the lock order of step 1 (see the class comment). A fresh "pending" row for C is someone's
     * attach in flight: nothing changes and the move stays due (not a skip, no attempt used). A failed or stale row is
     * claimed exactly as ServiceInboundAttacher claims it, so that "Retry attach" finds it taken.
     *
     * @param array<string, mixed> $move
     *
     * @return array{result: string, skip: string|null, otherRun: int|null, claimedAt: string, attempts: int, identity: list<string|null>, heldRow: int|null}
     */
    private function claimAdd(array $move, ?VpnInbound $target, InboundMoveScope $scope, int $runId): array
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $move['service_id'];
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');

        return $this->transactional(function (Connection $db) use ($moveId, $serviceId, $target, $scope, $runId, $now, $at): array {
            $service = $db->fetchAssociative('SELECT id, status, inbound_id, panel_id, remote_id, client_uuid, sub_id, order_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id, status, updated_at FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            $none = ['result' => self::NOT_DUE, 'skip' => null, 'otherRun' => null, 'claimedAt' => $at, 'attempts' => 0, 'identity' => [], 'heldRow' => null];
            if (false === $service || false === $row || !$this->due($row, $now)) {
                return $none;
            }
            // A plain read (no lock): the order row is never locked by a move (5.3, 5.7).
            $service['order_type'] = null === $service['order_id'] ? null : $db->fetchOne('SELECT type FROM `order` WHERE id = ?', [(int) $service['order_id']]);

            $additional = null;
            foreach ($rows as $one) {
                if ($target instanceof VpnInbound && (int) $one['inbound_id'] === (int) $target->getId()) {
                    $additional = $one;
                }
            }
            [$skip, $otherRun] = [null, null];
            if (!$target instanceof VpnInbound) {
                $skip = InboundMovePlanner::SKIP_TARGET_GONE;
            } elseif (1 !== (int) $db->fetchOne('SELECT is_active FROM vpn_inbound WHERE id = ?', [(int) $target->getId()])) {
                $skip = InboundMovePlanner::SKIP_TARGET_INACTIVE;
            } else {
                [$skip, $otherRun] = $this->planner->addSkipReason($service, $target, $scope, $runId);
                if (null === $skip && VpnServiceInbound::STATUS_ATTACHED === ($additional['status'] ?? null)) {
                    $skip = InboundMovePlanner::SKIP_ALREADY_ON_TARGET;
                }
            }
            if (null !== $skip) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, $skip, $at, $moveId],
                );

                return ['result' => self::SETTLED, 'skip' => $skip, 'otherRun' => $otherRun] + $none;
            }
            $held = null;
            if (null !== $additional && VpnServiceInbound::STATUS_DETACHED !== $additional['status']) {
                $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
                if (VpnServiceInbound::STATUS_PENDING === $additional['status'] && (string) $additional['updated_at'] >= $stale) {
                    return $none;
                }
                $held = (int) $additional['id'];
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, updated_at = ?, last_error = CASE WHEN last_error = ? THEN ? ELSE last_error END WHERE id = ?',
                    [VpnServiceInbound::STATUS_PENDING, $at, InboundAttachDriverInterface::AFTER_SALE, ServiceInboundAttacher::ATTACHING, $held],
                );
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
                'heldRow' => $held,
            ];
        });
    }

    /**
     * The panel step of a claimed add, then the row, then the links. Only a first attempt that finds C can say C was
     * there before this run: a later one may be finding an attach of its own earlier try.
     *
     * @param array<string, mixed>                                                                                                        $move
     * @param array{result: string, skip: string|null, otherRun: int|null, claimedAt: string, attempts: int, identity: list<string|null>, heldRow: int|null} $claim
     */
    private function attachAdded(VpnService $service, VpnInbound $target, array $move, array $claim, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $skip = function (string $reason) use ($serviceId, $moveId, $claim, $from, $to): InboundMoveItem {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_SKIPPED, $reason, $claim['heldRow']);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::addSkipText($reason));
        };
        $driver = $this->drivers->resolve($service->getPanel());
        if (!$driver instanceof InboundMoveDriverInterface) {
            return $skip(InboundMovePlanner::SKIP_NOT_MODERN);
        }
        self::assertNoTransaction($this->db());

        try {
            // Without $expectedFrom: the inbound the remote id records, the service's primary.
            $attach = $driver->attachForMove((string) $service->getRemoteId(), $target, $service->getPanel(), false);
        } catch (\Throwable $e) {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, 'Panel step failed: '.$e->getMessage(), 255, $this->planner->panelSecrets($service->getPanel())), ServiceInboundMove::STATE_ATTACHING, $claim['heldRow']);
            $this->logger->warning('inbound_move_panel_step_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'error' => $text]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, false);
        }
        $sent = InboundMoveAttachResult::ATTACHED === $attach->outcome
            || (InboundMoveAttachResult::FAILED === $attach->outcome && in_array($attach->reason, [InboundMoveAttachResult::FAILED_NOT_ATTACHED, InboundMoveAttachResult::FAILED_NOT_CONFIRMED], true));
        if (InboundMoveAttachResult::REFUSED === $attach->outcome) {
            return $skip(match ($attach->reason) {
                InboundMoveAttachResult::REFUSED_NOT_MODERN => InboundMovePlanner::SKIP_NOT_MODERN,
                InboundMoveAttachResult::REFUSED_INVALID_TARGET => InboundMovePlanner::SKIP_OTHER_PANEL,
                default => InboundMovePlanner::SKIP_REMOTE_ID,
            });
        }
        if (!$attach->onTarget()) {
            $message = InboundMoveAttachResult::FAILED_NOT_ON_EXPECTED === $attach->reason ? self::NOT_ON_PRIMARY : (string) $attach->message;
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, $message, 255, $this->planner->panelSecrets($service->getPanel())), ServiceInboundMove::STATE_ATTACHING, $claim['heldRow']);
            $this->logger->warning('inbound_move_attach_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'reason' => $attach->reason]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, $text, !$attach->panelUnreadable, $sent);
        }

        $note = InboundMoveAttachResult::ALREADY_ON_TARGET === $attach->outcome && 1 === $claim['attempts'] ? InboundMovePlanner::ALREADY_ON_PANEL : null;
        $written = $this->writeAdd($serviceId, $target, $moveId, $claim, $note);
        if (true !== $written) {
            if (InboundMoveItem::IDENTITY_CHANGED === $written) {
                $this->logger->warning('inbound_move_identity_changed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId]);

                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::IDENTITY_CHANGED, 'the client\'s credentials changed meanwhile (a link reset?): nothing written, picked again', true, $sent);
            }
            if (InboundMoveItem::SKIPPED === $written) {
                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, InboundMovePlanner::addSkipText(InboundMovePlanner::SKIP_DELETED), true, $sent);
            }

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'the claim was taken over meanwhile: nothing written', true, $sent);
        }
        $this->logger->info('inbound_move_added', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'to_inbound_id' => $target->getId(), 'attach_sent' => $sent]);

        $refresh = $this->refreshLinks($service);
        $this->settleAddLinks($moveId, $refresh);

        return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::ADDED, ($sent ? 'attached' : 'already on the panel: only the row was written').'; links '.self::addRefreshText($refresh), PanelLinkRefresh::FAILED !== $refresh->outcome, $sent, $refresh->outcome);
    }

    /**
     * The write of an add: C's row attached and the move "switched", in the lock order of step 1, only under this claim
     * and with the client's identity unchanged. vpn_service is never written: the links stay visible (6.4). An attached
     * row (someone else's attach finished first) is left as it is.
     *
     * @param array{claimedAt: string, identity: list<string|null>, heldRow: int|null} $claim
     *
     * @return true|string true when written; InboundMoveItem::IDENTITY_CHANGED, SKIPPED or BUSY when not
     */
    private function writeAdd(int $serviceId, VpnInbound $target, int $moveId, array $claim, ?string $note): bool|string
    {
        return $this->transactional(function (Connection $db) use ($serviceId, $target, $moveId, $claim, $note): bool|string {
            $now = self::now();
            $current = $db->fetchAssociative('SELECT id, status, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id, status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT state, claimed_at FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            if (false === $current || false === $row || ServiceInboundMove::STATE_ATTACHING !== $row['state'] || (string) $row['claimed_at'] !== $claim['claimedAt']) {
                return InboundMoveItem::BUSY;
            }
            if (VpnServiceStatus::DELETED === (string) $current['status']) {
                self::releaseRow($db, $claim['heldRow'], $claim['claimedAt']);
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_DELETED, $now, $moveId],
                );

                return InboundMoveItem::SKIPPED;
            }
            if (self::identity($current) !== $claim['identity']) {
                self::releaseRow($db, $claim['heldRow'], $claim['claimedAt']);
                $db->executeStatement('UPDATE service_inbound_move SET state = ?, claimed_at = NULL, updated_at = ? WHERE id = ?', [ServiceInboundMove::STATE_PLANNED, $now, $moveId]);

                return InboundMoveItem::IDENTITY_CHANGED;
            }
            $existing = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['inbound_id'] === (int) $target->getId()))[0] ?? null;
            if (null === $existing) {
                $db->insert('vpn_service_inbound', [
                    'service_id' => $serviceId,
                    'inbound_id' => $target->getId(),
                    'remote_inbound_id' => $target->getRemoteInboundId(),
                    'status' => VpnServiceInbound::STATUS_ATTACHED,
                    'attempts' => 0,
                    'attached_at' => $now,
                    'updated_at' => $now,
                    'checked_at' => $now,
                    'reattach_count' => 0,
                ]);
            } elseif (VpnServiceInbound::STATUS_ATTACHED !== $existing['status']) {
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, remote_inbound_id = ?, attempts = 0, last_error = NULL, next_attempt_at = NULL, alert_state = NULL, alerted_at = NULL, reattach_count = 0, reattach_since = NULL, attached_at = ?, checked_at = ?, updated_at = ? WHERE id = ?',
                    [VpnServiceInbound::STATUS_ATTACHED, $target->getRemoteInboundId(), $now, $now, $now, $existing['id']],
                );
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, skip_reason = ?, next_attempt_at = NULL, last_error = NULL, claimed_at = NULL, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_SWITCHED, $note, $now, $moveId],
            );

            return true;
        });
    }

    /**
     * switched → done once the links are in. A panel that answers with no links never holds an add's run open: the stored
     * links are still true (6.4), and the scheduled task would otherwise retry for ever. Only an unreadable panel does.
     */
    private function settleAddLinks(int $moveId, PanelLinkRefresh $refresh): void
    {
        if (PanelLinkRefresh::FAILED === $refresh->outcome) {
            return;
        }
        $this->db()->executeStatement(
            'UPDATE service_inbound_move SET state = ?, updated_at = ? WHERE id = ? AND state = ?',
            [ServiceInboundMove::STATE_DONE, self::now(), $moveId, ServiceInboundMove::STATE_SWITCHED],
        );
    }

    /** refreshText() for an add: only a failed read is retried (settleAddLinks()). */
    private static function addRefreshText(PanelLinkRefresh $refresh): string
    {
        $text = self::refreshText($refresh);

        return PanelLinkRefresh::FAILED === $refresh->outcome ? $text : str_replace(' (retried next pass)', '', $text);
    }

    /**
     * --undo of an add, one move: C off the client, then the row gone and the display guard on, then the links.
     * Shaped like a remove (docs/design/plan-inbound-add-remove.md 7): the row is held as "detached" from the claim on, so
     * the attach timer cannot put C back while the undo runs, or after one that failed.
     */
    private function undoAddOne(int $moveId, int $runId): InboundMoveItem
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
        if (ServiceInboundMove::STATE_UNDONE === $move['state']) {
            // Off the client already; only the links are left (an earlier --undo could not finish them).
            $claimedAt = self::now();
            $stale = (new \DateTimeImmutable())->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
            $taken = $this->db()->executeStatement(
                'UPDATE service_inbound_move SET claimed_at = ? WHERE id = ? AND state = ? AND next_attempt_at IS NOT NULL AND (claimed_at IS NULL OR claimed_at < ?)',
                [$claimedAt, $moveId, ServiceInboundMove::STATE_UNDONE, $stale],
            );
            if (1 !== $taken) {
                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile: run --undo again');
            }

            return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->finishUndoAdd($service, $move, $claimedAt, $from, $to));
        }
        $claim = $this->claimUndoAdd($move, $runId);
        if (self::NOT_DUE === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile: run --undo again');
        }
        if (null !== $claim['skip']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'not undone: '.InboundMovePlanner::addSkipText($claim['skip']));
        }
        $this->entityManager->refresh($service);

        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->removeAdded($service, $move, $claim, $from, $to));
    }

    /**
     * The claim of an add's undo, in the lock order of step 1. What may have put C on the client can be undone: switched,
     * done, failed, or a claim that went stale. A service whose primary is C now is left as it is (nothing claimed).
     *
     * @param array<string, mixed> $move
     *
     * @return array{result: string, skip: string|null, claimedAt: string, previous: string, identity: list<string|null>}
     */
    private function claimUndoAdd(array $move, int $runId): array
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $move['service_id'];
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');

        return $this->transactional(function (Connection $db) use ($moveId, $serviceId, $runId, $now, $at): array {
            $service = $db->fetchAssociative('SELECT id, status, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id, status, updated_at FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            $none = ['result' => self::NOT_DUE, 'skip' => null, 'claimedAt' => $at, 'previous' => ServiceInboundMove::STATE_FAILED, 'identity' => []];
            if (false === $service || false === $row) {
                return $none;
            }
            $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
            $unclaimed = null === $row['claimed_at'] || (string) $row['claimed_at'] < $stale;
            $reversible = InboundMovePlanner::ALREADY_ON_PANEL !== $row['skip_reason'] && match ($row['state']) {
                ServiceInboundMove::STATE_SWITCHED, ServiceInboundMove::STATE_DONE, ServiceInboundMove::STATE_FAILED => true,
                ServiceInboundMove::STATE_ATTACHING, ServiceInboundMove::STATE_DETACHING => $unclaimed,
                default => false,
            };
            if (!$reversible) {
                return $none;
            }
            $skip = null;
            if (VpnServiceStatus::DELETED === (string) $service['status']) {
                $skip = InboundMovePlanner::SKIP_DELETED;
            } elseif (null !== $service['inbound_id'] && (int) $service['inbound_id'] === (int) $row['to_inbound_id']) {
                $skip = InboundMovePlanner::SKIP_IS_PRIMARY;
            }
            if (null !== $skip) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, $skip, $at, $moveId],
                );

                return ['result' => self::SETTLED, 'skip' => $skip] + $none;
            }
            foreach ($rows as $one) {
                if ((int) $one['inbound_id'] !== (int) $row['to_inbound_id']) {
                    continue;
                }
                if (VpnServiceInbound::STATUS_PENDING === $one['status'] && (string) $one['updated_at'] >= $stale) {
                    return $none;
                }
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, last_error = ?, updated_at = ? WHERE id = ?',
                    [VpnServiceInbound::STATUS_DETACHED, VpnServiceInbound::safeError(sprintf(self::UNDO_ADD_HOLD, $runId)), $at, $one['id']],
                );
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = ?, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_DETACHING, $at, $at, $moveId],
            );
            $previous = in_array($row['state'], [ServiceInboundMove::STATE_SWITCHED, ServiceInboundMove::STATE_DONE], true) ? (string) $row['state'] : ServiceInboundMove::STATE_FAILED;

            return ['result' => self::CLAIMED, 'skip' => null, 'claimedAt' => $at, 'previous' => $previous, 'identity' => self::identity($service)];
        });
    }

    /**
     * The panel step of an add's undo. The driver acts on what the panel lists (5.3): it refuses the primary and the
     * client's last inbound, and those end skipped, with the row attached again. Any other failure keeps the row held,
     * with no back-off: --undo is pressed again by hand.
     *
     * @param array<string, mixed>                                                                         $move
     * @param array{result: string, skip: string|null, claimedAt: string, previous: string, identity: list<string|null>} $claim
     */
    private function removeAdded(VpnService $service, array $move, array $claim, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $panel = $service->getPanel();
        $driver = $this->drivers->resolve($panel);
        $hold = function (string $message, ?bool $panelRead) use ($service, $serviceId, $moveId, $claim, $from, $to, $panel, $move): InboundMoveItem {
            $text = VpnServiceInbound::safeError(SafeText::forService($service, $message, 255, $this->planner->panelSecrets($panel)));
            $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claim, $text): void {
                $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
                $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, last_error = ?, next_attempt_at = NULL, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                    [ServiceInboundMove::STATE_FAILED, $text, self::now(), $moveId, ServiceInboundMove::STATE_DETACHING, $claim['claimedAt']],
                );
            });
            $this->logger->warning('inbound_move_add_undo_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'error' => $text]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'not undone: '.$text.' (run --undo again)', $panelRead);
        };
        $skip = function (string $reason) use ($serviceId, $moveId, $claim, $from, $to, $move): InboundMoveItem {
            $this->releaseUndoAdd($serviceId, $moveId, $claim['claimedAt'], $move['to_inbound_id'], ServiceInboundMove::STATE_SKIPPED, $reason);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'not undone: '.InboundMovePlanner::addSkipText($reason), true);
        };
        if (!$panel instanceof VpnPanel || !$driver instanceof InboundDetachDriverInterface || !$driver instanceof InboundMoveDriverInterface) {
            return $skip(InboundMovePlanner::SKIP_NOT_MODERN);
        }
        self::assertNoTransaction($this->db());
        try {
            $result = $driver->detachInbounds((string) $service->getRemoteId(), $service->getInbound(), [$this->inboundOf($move['to_inbound_id'], (string) $move['to_remote_inbound_id'], $panel, 'added inbound')], $panel, false);
        } catch (\Throwable $e) {
            return $hold('Panel step failed: '.$e->getMessage(), false);
        }
        if (!$result->succeeded()) {
            if (InboundDetachResult::REFUSED === $result->outcome && InboundDetachResult::REFUSED_NOT_MODERN === $result->reason) {
                return $skip(InboundMovePlanner::SKIP_NOT_MODERN);
            }
            if (InboundDetachResult::REFUSED_PRIMARY === $result->reason) {
                return $skip(InboundMovePlanner::SKIP_IS_PRIMARY);
            }
            if (InboundDetachResult::REFUSED_LAST_INBOUND === $result->reason) {
                return $skip(InboundMovePlanner::SKIP_LAST_INBOUND);
            }
            if (InboundDetachResult::REFUSED_NOT_THIS_CLIENT === $result->reason && $this->identityChanged($serviceId, $claim['identity'])) {
                $this->releaseUndoAdd($serviceId, $moveId, $claim['claimedAt'], $move['to_inbound_id'], $claim['previous'], null);

                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::IDENTITY_CHANGED, 'the client\'s credentials changed meanwhile (a link reset?): nothing sent, picked again', true);
            }
            if (InboundDetachResult::FAILED === $result->outcome && InboundDetachResult::FAILED_PANEL === $result->reason) {
                // 3x-ui refuses a detach of an inbound deleted on the panel: done only when inbounds/list confirms it is gone.
                $has = $driver->panelHasInbound($panel, (int) $move['to_remote_inbound_id'], false);
                if (false !== $has) {
                    return $hold((string) $result->message.(null === $has ? ' (the panel\'s inbound list could not be read)' : ''), null !== $has);
                }
            } else {
                return $hold((string) $result->message, InboundDetachResult::FAILED_UNREADABLE !== $result->reason);
            }
        }

        $written = $this->writeUndoAdd($serviceId, $move, $claim);
        if (InboundMoveItem::IDENTITY_CHANGED === $written) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::IDENTITY_CHANGED, 'the client\'s credentials changed meanwhile: nothing written, picked again', true);
        }
        if (null === $written) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'the claim was taken over meanwhile: nothing written', true);
        }
        $this->logger->info('inbound_move_add_undone', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'to_inbound_id' => $move['to_inbound_id']]);

        return $this->finishUndoAdd($service, $move, $written, $from, $to);
    }

    /**
     * The write after a confirmed removal: the row gone, the move "undone" with its links still owed, and the display
     * guard on in the same transaction (the stored links hold C's, a dead link now).
     *
     * @param array<string, mixed>                                                 $move
     * @param array{claimedAt: string, previous: string, identity: list<string|null>} $claim
     *
     * @return string|null the new claim, InboundMoveItem::IDENTITY_CHANGED, or null when the claim is gone
     */
    private function writeUndoAdd(int $serviceId, array $move, array $claim): ?string
    {
        $moveId = (int) $move['id'];

        return $this->transactional(function (Connection $db) use ($serviceId, $moveId, $move, $claim): ?string {
            $now = self::now();
            $current = $db->fetchAssociative('SELECT id, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT state, claimed_at FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            if (false === $current || false === $row || ServiceInboundMove::STATE_DETACHING !== $row['state'] || (string) $row['claimed_at'] !== $claim['claimedAt']) {
                return null;
            }
            if (self::identity($current) !== $claim['identity']) {
                // The row stays held; picked again at once, and the driver then finds C gone.
                $db->executeStatement('UPDATE service_inbound_move SET state = ?, claimed_at = NULL, updated_at = ? WHERE id = ?', [$claim['previous'], $now, $moveId]);

                return InboundMoveItem::IDENTITY_CHANGED;
            }
            if (null !== $move['to_inbound_id']) {
                $db->executeStatement('DELETE FROM vpn_service_inbound WHERE service_id = ? AND inbound_id = ?', [$serviceId, (int) $move['to_inbound_id']]);
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, detached_at = ?, last_error = NULL, next_attempt_at = ?, claimed_at = ?, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_UNDONE, $now, $now, $now, $now, $moveId],
            );
            // Both stamps have one-second precision: see finishRetire().
            $db->executeStatement('UPDATE vpn_service SET inbound_changed_at = ?, last_access_info_synced_at = NULL WHERE id = ?', [$now, $serviceId]);

            return $now;
        });
    }

    /**
     * The refresh after an add's undo. The move is finished only when the links are the panel's again (the guard is off):
     * otherwise --undo is pressed again, for the links alone.
     *
     * @param array<string, mixed> $move
     */
    private function finishUndoAdd(VpnService $service, array $move, string $claimedAt, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $refresh = $this->refreshLinks($service);
        $current = in_array($refresh->outcome, [PanelLinkRefresh::UPDATED, PanelLinkRefresh::UNCHANGED, PanelLinkRefresh::CHANGED_MEANWHILE], true);
        $this->db()->executeStatement(
            $current
                ? 'UPDATE service_inbound_move SET next_attempt_at = NULL, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?'
                : 'UPDATE service_inbound_move SET claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
            [self::now(), $moveId, ServiceInboundMove::STATE_UNDONE, $claimedAt],
        );
        if (!$current) {
            return new InboundMoveItem($moveId, (int) $service->getId(), $from, $to, InboundMoveItem::FAILED, 'the inbound is off the client; links '.self::refreshText($refresh).' (run --undo again)', false, false, $refresh->outcome);
        }

        return new InboundMoveItem($moveId, (int) $service->getId(), $from, $to, InboundMoveItem::UNDONE, 'the inbound is off the client; links '.self::refreshText($refresh), true, false, $refresh->outcome);
    }

    /** An add's undo that ends without a removal: the state given, the held row attached again. */
    private function releaseUndoAdd(int $serviceId, int $moveId, string $claimedAt, mixed $inboundId, string $state, ?string $reason): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $inboundId, $state, $reason): void {
            $now = self::now();
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $changed = $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, skip_reason = COALESCE(?, skip_reason), claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [$state, $reason, $now, $moveId, ServiceInboundMove::STATE_DETACHING, $claimedAt],
            );
            if (1 === $changed && null !== $inboundId) {
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, last_error = NULL, updated_at = ? WHERE service_id = ? AND inbound_id = ? AND status = ?',
                    [VpnServiceInbound::STATUS_ATTACHED, $now, $serviceId, (int) $inboundId, VpnServiceInbound::STATUS_DETACHED],
                );
            }
        });
    }

    /** A claimed row given back after a step that attached nothing: failed, due as it was (attempts and error unchanged). */
    private static function releaseRow(Connection $db, ?int $rowId, string $claimedAt): void
    {
        if (null === $rowId) {
            return;
        }
        $db->executeStatement(
            'UPDATE vpn_service_inbound SET status = ?, updated_at = ? WHERE id = ? AND status = ? AND updated_at = ?',
            [VpnServiceInbound::STATUS_FAILED, self::now(), $rowId, VpnServiceInbound::STATUS_PENDING, $claimedAt],
        );
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

                return ['result' => self::SETTLED, 'skip' => $skip, 'otherRun' => $otherRun] + $none;
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
     * @param list<int>                                  $onPanel    the client's remote inbound ids as read after step 2
     * @param int|null                                   $graceHours null: keep the old inbound (never retired automatically)
     *
     * @return true|string true when switched; InboundMoveItem::IDENTITY_CHANGED or BUSY when nothing was written
     */
    private function write(VpnService $service, VpnInbound $target, Plan $plan, array $move, array $claim, array $onPanel, ?int $graceHours): bool|string
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $fromId = null === $move['from_inbound_id'] ? null : (int) $move['from_inbound_id'];
        $fromRemote = null === $move['from_remote_inbound_id'] ? null : (string) $move['from_remote_inbound_id'];
        $keepFrom = null !== $fromId && in_array($fromId, $this->planner->planAdditionalIds((int) $plan->getId()), true);

        $result = $this->transactional(function (Connection $db) use ($serviceId, $moveId, $target, $claim, $fromId, $fromRemote, $keepFrom, $onPanel, $graceHours): bool|string {
            $nowAt = new \DateTimeImmutable();
            $now = $nowAt->format('Y-m-d H:i:s');
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
                'UPDATE vpn_service SET inbound_id = ?, remote_id = ?, inbound_changed_at = ?, last_access_info_synced_at = NULL WHERE id = ?',
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
            // Phase 2 (P6): A is removed after the grace period; never when kept (--keep-old) or still an additional inbound.
            $detachAfter = $keepFrom || null === $graceHours ? null : $nowAt->modify(sprintf('+%d hours', $graceHours))->format('Y-m-d H:i:s');
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, detach_after = ?, next_attempt_at = NULL, last_error = NULL, claimed_at = NULL, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_SWITCHED, $detachAfter, $now, $moveId],
            );

            return true;
        });
        // Written with SQL: what this entity manager holds is stale now.
        $this->entityManager->refresh($service);

        return $result;
    }

    /**
     * Step 5: claim, then the panel step within its own budget.
     *
     * @param array<string, mixed> $move
     */
    private function retireOne(VpnService $service, array $move, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $claim = $this->claimRetire($move);
        if (self::NOT_DUE === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile, or no longer due');
        }
        if (self::KEPT === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::KEPT, 'the old inbound is one of the service\'s additional inbounds now: it stays attached (nothing detached)');
        }
        if (null !== $claim['skip']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'old inbound not removed: '.InboundMovePlanner::skipText($claim['skip']));
        }
        $this->entityManager->refresh($service);

        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->retire($service, $move, $claim, $from, $to));
    }

    /**
     * Step 5 claim: the same lock order as step 1; due (detach_after passed, a stale "detaching" claim, or a failed
     * retire due again); the service still on B. A deleted service, or one on another inbound now, is skipped; one that
     * has A as an additional inbound now keeps it.
     *
     * @param array<string, mixed> $move
     *
     * @return array{result: string, skip: string|null, claimedAt: string, attempts: int, identity: list<string|null>}
     */
    private function claimRetire(array $move): array
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $move['service_id'];
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');

        return $this->transactional(function (Connection $db) use ($moveId, $serviceId, $now, $at): array {
            $service = $db->fetchAssociative('SELECT id, status, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $additional = array_map('intval', $db->fetchFirstColumn('SELECT inbound_id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]));
            $row = $db->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            $none = ['result' => self::NOT_DUE, 'skip' => null, 'claimedAt' => $at, 'attempts' => 0, 'identity' => []];
            if (false === $service || false === $row || !$this->retireDue($row, $now)) {
                return $none;
            }
            $fromId = null === $row['from_inbound_id'] ? null : (int) $row['from_inbound_id'];
            $skip = null;
            if (VpnServiceStatus::DELETED === (string) $service['status']) {
                $skip = InboundMovePlanner::SKIP_DELETED;
            } elseif (null === $service['inbound_id'] || null === $row['to_inbound_id'] || (int) $service['inbound_id'] !== (int) $row['to_inbound_id']) {
                $skip = InboundMovePlanner::SKIP_INBOUND_CHANGED;
            } elseif (!ctype_digit(trim((string) $row['from_remote_inbound_id'])) || (int) $row['from_remote_inbound_id'] <= 0) {
                $skip = InboundMovePlanner::SKIP_REMOTE_ID;
            } elseif (null !== $fromId && in_array($fromId, $additional, true)) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, detach_after = NULL, claimed_at = NULL, next_attempt_at = NULL, last_error = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SWITCHED, $at, $moveId],
                );

                return ['result' => self::KEPT] + $none;
            }
            if (null !== $skip) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, $skip, $at, $moveId],
                );

                return ['result' => self::SETTLED, 'skip' => $skip] + $none;
            }
            // Attempts count the retire step on its own (phase 1's attempts are over once switched).
            $attempts = ServiceInboundMove::STATE_SWITCHED === $row['state'] ? 1 : (int) $row['attempts'] + 1;
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = ?, attempts = ?, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_DETACHING, $at, $attempts, $at, $moveId],
            );

            return ['result' => self::CLAIMED, 'skip' => null, 'claimedAt' => $at, 'attempts' => $attempts, 'identity' => self::identity($service)];
        });
    }

    /**
     * Step 5 panel step for a claimed retire (see the class comment). At most 4 panel calls: GET, detach, GET and
     * subLinks; or GET, then inbounds/list after a refused detach; or GET then the re-attach of B (GET, attach, GET).
     *
     * @param array<string, mixed>                                                             $move
     * @param array{result: string, skip: string|null, claimedAt: string, attempts: int, identity: list<string|null>} $claim
     */
    private function retire(VpnService $service, array $move, array $claim, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $panel = $service->getPanel();
        $driver = $this->drivers->resolve($panel);
        if (!$panel instanceof VpnPanel || !$driver instanceof InboundDetachDriverInterface || !$driver instanceof InboundMoveDriverInterface) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_DETACHING, ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_NOT_MODERN);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'old inbound not removed: '.InboundMovePlanner::skipText(InboundMovePlanner::SKIP_NOT_MODERN));
        }
        self::assertNoTransaction($this->db());
        $fromRemote = (int) $move['from_remote_inbound_id'];
        $toRemote = (int) $move['to_remote_inbound_id'];
        $secrets = $this->planner->panelSecrets($panel);
        $failed = function (string $message, ?bool $panelRead) use ($service, $serviceId, $moveId, $claim, $from, $to, $secrets, $move): InboundMoveItem {
            $text = $this->fail($serviceId, $moveId, $claim['claimedAt'], $claim['attempts'], SafeText::forService($service, $message, 255, $secrets), ServiceInboundMove::STATE_DETACHING);
            $this->logger->warning('inbound_move_retire_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'error' => $text]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'old inbound not removed: '.$text, $panelRead);
        };

        try {
            $result = $driver->detachInbounds((string) $service->getRemoteId(), $service->getInbound(), [$this->oldInbound($move, $panel)], $panel, false);
        } catch (\Throwable $e) {
            return $failed('Panel step failed: '.$e->getMessage(), false);
        }
        if ($result->succeeded()) {
            return $this->finishRetire($service, $move, $claim, $from, $to, InboundDetachResult::DONE === $result->outcome ? 'old inbound removed' : 'the old inbound was already off the client (nothing sent)');
        }
        $listed = $result->inboundIdsAfter;
        if (InboundDetachResult::REFUSED === $result->outcome && InboundDetachResult::REFUSED_NOT_MODERN === $result->reason) {
            $this->settleClaim($serviceId, $moveId, $claim['claimedAt'], ServiceInboundMove::STATE_DETACHING, ServiceInboundMove::STATE_SKIPPED, InboundMovePlanner::SKIP_NOT_MODERN);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'old inbound not removed: '.InboundMovePlanner::skipText(InboundMovePlanner::SKIP_NOT_MODERN));
        }
        if (InboundDetachResult::REFUSED_NOT_THIS_CLIENT === $result->reason && $this->identityChanged($serviceId, $claim['identity'])) {
            $this->releaseRetire($serviceId, $moveId, $claim['claimedAt']);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::IDENTITY_CHANGED, 'the client\'s credentials changed meanwhile (a link reset?): nothing sent, picked again', true);
        }
        if (null !== $listed && in_array($result->reason, [InboundDetachResult::REFUSED_LAST_INBOUND, InboundDetachResult::FAILED_NOT_ON_PRIMARY], true) && !in_array($toRemote, $listed, true)) {
            if (!in_array($fromRemote, $listed, true)) {
                return $failed(self::NEITHER_INBOUND, true);
            }
            // B missing on the panel (removed by hand?): attached again FIRST; A is never detached without B listed.
            $target = $service->getInbound();
            \assert($target instanceof VpnInbound);
            $attach = $driver->attachForMove((string) $service->getRemoteId(), $target, $panel, false, $fromRemote);
            if (!$attach->onTarget()) {
                return $failed('The new inbound was missing on the panel and could not be attached again: '.(string) $attach->message, !$attach->panelUnreadable);
            }
            $this->releaseRetire($serviceId, $moveId, $claim['claimedAt']);
            $this->logger->warning('inbound_move_retire_reattached', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::REATTACHED, 'the new inbound was missing on the panel: attached again first (the old one is removed next)', true, true);
        }
        if (InboundDetachResult::FAILED === $result->outcome && InboundDetachResult::FAILED_PANEL === $result->reason) {
            // 3x-ui refuses a detach of an inbound deleted on the panel: done only when inbounds/list confirms it is gone.
            $has = $driver->panelHasInbound($panel, $fromRemote, false);
            if (false === $has) {
                return $this->finishRetire($service, $move, $claim, $from, $to, 'the old inbound no longer exists on the panel (inbounds/list)');
            }

            return $failed((string) $result->message.(null === $has ? ' (the panel\'s inbound list could not be read)' : ''), null !== $has);
        }

        return $failed((string) $result->message, InboundDetachResult::FAILED_UNREADABLE !== $result->reason);
    }

    /**
     * A confirmed retire: "done", detached_at and inbound_changed_at (only while this claim holds), then the refresh.
     *
     * @param array<string, mixed>                $move
     * @param array{claimedAt: string}            $claim
     */
    private function finishRetire(VpnService $service, array $move, array $claim, string $from, string $to, string $how): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $written = $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claim): bool {
            $now = self::now();
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT state, claimed_at FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            if (false === $row || ServiceInboundMove::STATE_DETACHING !== $row['state'] || (string) $row['claimed_at'] !== $claim['claimedAt']) {
                return false;
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, detached_at = ?, claimed_at = NULL, next_attempt_at = NULL, last_error = NULL, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_DONE, $now, $now, $moveId],
            );
            // The stored links still hold A's: hidden by the display guard (5.5) until the refresh below succeeds. Both
            // stamps have one-second precision, so the synced one is cleared: a refresh in this same second must not read
            // as already done after a crash here.
            $db->executeStatement('UPDATE vpn_service SET inbound_changed_at = ?, last_access_info_synced_at = NULL WHERE id = ?', [$now, $serviceId]);

            return true;
        });
        if (!$written) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'the claim was taken over meanwhile: nothing written', true);
        }
        $this->logger->info('inbound_move_retired', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'from_inbound_id' => $move['from_inbound_id']]);
        $refresh = $this->refreshLinks($service);

        return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::RETIRED, $how.'; links '.self::refreshText($refresh), PanelLinkRefresh::FAILED !== $refresh->outcome, false, $refresh->outcome);
    }

    /** A retire claim given back without a result: the move is switched (and due) again. */
    private function releaseRetire(int $serviceId, int $moveId, string $claimedAt): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [ServiceInboundMove::STATE_SWITCHED, self::now(), $moveId, ServiceInboundMove::STATE_DETACHING, $claimedAt],
            );
        });
    }

    /**
     * The old inbound A as the driver needs it: the local row when it still names the remote inbound the move recorded,
     * otherwise an unsaved stand-in with that remote id on the service's panel (A deleted or edited locally).
     *
     * @param array<string, mixed> $move
     */
    private function oldInbound(array $move, VpnPanel $panel): VpnInbound
    {
        return $this->inboundOf($move['from_inbound_id'], (string) $move['from_remote_inbound_id'], $panel, 'old inbound');
    }

    private function inboundOf(mixed $localId, string $remoteId, VpnPanel $panel, string $title): VpnInbound
    {
        $local = null === $localId ? null : $this->entityManager->find(VpnInbound::class, (int) $localId);
        if ($local instanceof VpnInbound && trim($local->getRemoteInboundId()) === trim($remoteId) && $local->getPanel()->getId() === $panel->getId()) {
            return $local;
        }

        return (new VpnInbound())->setPanel($panel)->setRemoteInboundId(trim($remoteId))->setTitle($title);
    }

    /**
     * --undo, one move (see undo()).
     */
    private function undoOne(int $moveId, int $planId): InboundMoveItem
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
        if (ServiceInboundMove::STATE_UNDONE === $move['state']) {
            // Back on A already; only the removal of B is left (an earlier --undo could not finish it).
            return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->undoDetachNew($service, $move, null, $from, $to));
        }
        $claim = $this->claimUndo($move);
        if (self::NOT_DUE === $claim['result']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile: run --undo again');
        }
        if (null !== $claim['skip']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::SKIPPED, 'not undone: '.InboundMovePlanner::skipText($claim['skip']));
        }
        if (null !== $claim['error']) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'not undone: '.$claim['error']);
        }
        $this->entityManager->refresh($service);
        $old = $this->entityManager->find(VpnInbound::class, (int) $move['from_inbound_id']);
        $panel = $service->getPanel();
        $driver = $this->drivers->resolve($panel);
        if (!$old instanceof VpnInbound || !$panel instanceof VpnPanel || !$driver instanceof InboundMoveDriverInterface || !$driver instanceof InboundDetachDriverInterface) {
            $this->releaseUndo($serviceId, $moveId, $claim['claimedAt'], $claim['previous'], 'not undone: the panel is not in modern mode, or the old inbound is gone');

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'not undone: the panel is not in modern mode, or the old inbound is gone');
        }

        // Step 1: the client on A again (GET; A missing → attach A; GET). No lock, no transaction.
        $attach = $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, function () use ($driver, $service, $old, $panel): InboundMoveAttachResult|\Throwable {
            self::assertNoTransaction($this->db());
            try {
                return $driver->attachForMove((string) $service->getRemoteId(), $old, $panel, false);
            } catch (\Throwable $e) {
                return $e;
            }
        });
        if ($attach instanceof \Throwable || !$attach->onTarget()) {
            $message = $attach instanceof \Throwable ? 'Panel step failed: '.$attach->getMessage() : (string) $attach->message;
            $text = VpnServiceInbound::safeError(SafeText::forService($service, $message, 255, $this->planner->panelSecrets($panel)));
            $this->releaseUndo($serviceId, $moveId, $claim['claimedAt'], $claim['previous'], $text);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'not undone: '.$text.' (run --undo again)', $attach instanceof \Throwable ? false : !$attach->panelUnreadable);
        }

        // Step 2: the service on A (same lock order and identity rule as step 3).
        $keepNew = null !== $move['to_inbound_id'] && in_array((int) $move['to_inbound_id'], $this->planner->planAdditionalIds($planId), true);
        $written = $this->writeUndo($service, $old, $move, $claim, $keepNew, $attach->inboundIds ?? []);
        if (true !== $written) {
            return new InboundMoveItem($moveId, $serviceId, $from, $to, $written, InboundMoveItem::IDENTITY_CHANGED === $written ? 'the client\'s credentials changed meanwhile: nothing written, picked again' : 'not undone: '.$written, true);
        }
        $this->logger->info('inbound_move_undone', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'keep_new' => $keepNew]);
        if ($keepNew) {
            $refresh = $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): PanelLinkRefresh => $this->refreshLinks($service));

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::UNDONE, 'back on the old inbound; the new one stays attached (one of the plan\'s additional inbounds); links '.self::refreshText($refresh), true, false, $refresh->outcome);
        }
        $row = $this->db()->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ?', [$moveId]) ?: $move;

        // Step 3: B off the client (detachInbounds' guards: never the primary A, never the last inbound), then the links.
        return $this->budget->within(InboundMovePlanner::STEP_BUDGET_SECONDS, fn (): InboundMoveItem => $this->undoDetachNew($service, $row, (string) $row['claimed_at'], $from, $to));
    }

    /**
     * --undo claim: the same lock order; the service still on B; "detaching" with claimed_at (the previous state is
     * restored when the undo of this service fails before its write).
     *
     * @param array<string, mixed> $move
     *
     * @return array{result: string, skip: string|null, error: string|null, claimedAt: string, previous: string, identity: list<string|null>}
     */
    private function claimUndo(array $move): array
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $move['service_id'];
        $now = new \DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');

        return $this->transactional(function (Connection $db) use ($moveId, $serviceId, $now, $at): array {
            $service = $db->fetchAssociative('SELECT id, status, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT * FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            $none = ['result' => self::NOT_DUE, 'skip' => null, 'error' => null, 'claimedAt' => $at, 'previous' => ServiceInboundMove::STATE_SWITCHED, 'identity' => []];
            $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
            $reversible = false !== $row && (ServiceInboundMove::STATE_SWITCHED === $row['state']
                || (ServiceInboundMove::STATE_FAILED === $row['state'] && null !== $row['detach_after'])
                || (ServiceInboundMove::STATE_DETACHING === $row['state'] && (null === $row['claimed_at'] || (string) $row['claimed_at'] < $stale)));
            if (false === $service || false === $row || !$reversible) {
                return $none;
            }
            $previous = ServiceInboundMove::STATE_FAILED === $row['state'] ? ServiceInboundMove::STATE_FAILED : ServiceInboundMove::STATE_SWITCHED;
            if (VpnServiceStatus::DELETED === (string) $service['status']) {
                $skip = InboundMovePlanner::SKIP_DELETED;
            } elseif (null === $service['inbound_id'] || null === $row['to_inbound_id'] || (int) $service['inbound_id'] !== (int) $row['to_inbound_id']) {
                $skip = InboundMovePlanner::SKIP_INBOUND_CHANGED;
            } else {
                $skip = null;
            }
            if (null !== $skip) {
                $db->executeStatement(
                    'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ?',
                    [ServiceInboundMove::STATE_SKIPPED, $skip, $at, $moveId],
                );

                return ['result' => self::SETTLED, 'skip' => $skip] + $none;
            }
            if (null === $row['from_inbound_id'] || 1 !== (int) $db->fetchOne('SELECT COUNT(*) FROM vpn_inbound WHERE id = ?', [(int) $row['from_inbound_id']])) {
                return ['result' => self::SETTLED, 'error' => 'the old inbound was deleted in the bot: the service cannot be put back on it'] + $none;
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = ?, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_DETACHING, $at, $at, $moveId],
            );

            return ['result' => self::CLAIMED, 'skip' => null, 'error' => null, 'claimedAt' => $at, 'previous' => $previous, 'identity' => self::identity($service)];
        });
    }

    /**
     * --undo write: the service back on A (remote id parts rewritten, inbound_changed_at), A's additional row deleted, an
     * additional row for B when B is one of the plan's additional inbounds; the move "undone" (next_attempt_at and
     * claimed_at set while B is still to be removed).
     *
     * @param array<string, mixed>                                $move
     * @param array{claimedAt: string, previous: string, identity: list<string|null>} $claim
     * @param list<int>                                           $onPanel the client's remote inbound ids as read after the attach of A
     *
     * @return true|string true when written; InboundMoveItem::IDENTITY_CHANGED, or why nothing was written
     */
    private function writeUndo(VpnService $service, VpnInbound $old, array $move, array $claim, bool $keepNew, array $onPanel): bool|string
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        $toId = (int) $move['to_inbound_id'];
        $toRemote = (string) $move['to_remote_inbound_id'];

        $result = $this->transactional(function (Connection $db) use ($serviceId, $moveId, $old, $claim, $keepNew, $toId, $toRemote, $onPanel): bool|string {
            $now = self::now();
            $current = $db->fetchAssociative('SELECT id, inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $row = $db->fetchAssociative('SELECT state, claimed_at FROM service_inbound_move WHERE id = ? FOR UPDATE', [$moveId]);
            if (false === $current || false === $row || ServiceInboundMove::STATE_DETACHING !== $row['state'] || (string) $row['claimed_at'] !== $claim['claimedAt']) {
                return 'the claim was taken over meanwhile: nothing written';
            }
            $back = static fn (?string $error) => $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = NULL, last_error = COALESCE(?, last_error), updated_at = ? WHERE id = ?',
                [$claim['previous'], $error, $now, $moveId],
            );
            if (self::identity($current) !== $claim['identity']) {
                $back(null);

                return InboundMoveItem::IDENTITY_CHANGED;
            }
            $remoteId = $this->remoteIdParser->withInbound((string) $current['remote_id'], (int) $old->getId(), $old->getRemoteInboundId());
            if (null === $remoteId) {
                $back('undo: the remote id cannot be rewritten');

                return 'the service\'s remote id cannot be rewritten (not the structured form)';
            }
            $db->executeStatement('UPDATE vpn_service SET inbound_id = ?, remote_id = ?, inbound_changed_at = ?, last_access_info_synced_at = NULL WHERE id = ?', [$old->getId(), $remoteId, $now, $serviceId]);
            // A is the primary again: never an additional row of its own.
            $db->executeStatement('DELETE FROM vpn_service_inbound WHERE service_id = ? AND inbound_id = ?', [$serviceId, $old->getId()]);
            $hasNew = [] !== array_filter($rows, static fn (array $r): bool => (int) $r['inbound_id'] === $toId);
            if ($keepNew && !$hasNew) {
                $listed = in_array((int) $toRemote, $onPanel, true);
                $db->insert('vpn_service_inbound', [
                    'service_id' => $serviceId,
                    'inbound_id' => $toId,
                    'remote_inbound_id' => $toRemote,
                    'status' => $listed ? VpnServiceInbound::STATUS_ATTACHED : VpnServiceInbound::STATUS_FAILED,
                    'attempts' => 0,
                    'last_error' => $listed ? null : 'not on the panel when the move was undone',
                    'attached_at' => $listed ? $now : null,
                    'updated_at' => $now,
                    'checked_at' => $now,
                    'reattach_count' => 0,
                ]);
            }
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, detach_after = NULL, last_error = NULL, next_attempt_at = ?, claimed_at = ?, updated_at = ? WHERE id = ?',
                [ServiceInboundMove::STATE_UNDONE, $keepNew ? null : $now, $keepNew ? null : $now, $now, $moveId],
            );

            return true;
        });
        $this->entityManager->refresh($service);

        return $result;
    }

    /**
     * --undo, the removal of B from a service back on A (claimed by $claimedAt, or claimed here), then the refresh.
     *
     * @param array<string, mixed> $move
     */
    private function undoDetachNew(VpnService $service, array $move, ?string $claimedAt, string $from, string $to): InboundMoveItem
    {
        $moveId = (int) $move['id'];
        $serviceId = (int) $service->getId();
        if (null === $claimedAt) {
            $claimedAt = self::now();
            $stale = (new \DateTimeImmutable())->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
            $taken = $this->db()->executeStatement(
                'UPDATE service_inbound_move SET claimed_at = ? WHERE id = ? AND state = ? AND next_attempt_at IS NOT NULL AND (claimed_at IS NULL OR claimed_at < ?)',
                [$claimedAt, $moveId, ServiceInboundMove::STATE_UNDONE, $stale],
            );
            if (1 !== $taken) {
                return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::BUSY, 'claimed by another process meanwhile: run --undo again');
            }
        }
        $this->entityManager->refresh($service);
        $panel = $service->getPanel();
        $driver = $this->drivers->resolve($panel);
        $result = null;
        $error = null;
        if (!$panel instanceof VpnPanel || !$driver instanceof InboundDetachDriverInterface || !$driver instanceof InboundMoveDriverInterface) {
            $error = InboundMovePlanner::skipText(InboundMovePlanner::SKIP_NOT_MODERN);
        } else {
            self::assertNoTransaction($this->db());
            $new = $this->inboundOf($move['to_inbound_id'], (string) $move['to_remote_inbound_id'], $panel, 'new inbound');
            try {
                $result = $driver->detachInbounds((string) $service->getRemoteId(), $service->getInbound(), [$new], $panel, false);
                if (!$result->succeeded()) {
                    $gone = InboundDetachResult::FAILED_PANEL === $result->reason ? $driver->panelHasInbound($panel, (int) $move['to_remote_inbound_id'], false) : null;
                    $error = false === $gone ? null : SafeText::forService($service, (string) $result->message, 255, $this->planner->panelSecrets($panel));
                }
            } catch (\Throwable $e) {
                $error = SafeText::forService($service, 'Panel step failed: '.$e->getMessage(), 255, $this->planner->panelSecrets($panel));
            }
        }
        $error = null === $error ? null : VpnServiceInbound::safeError($error);
        $this->db()->executeStatement(
            null === $error
                ? 'UPDATE service_inbound_move SET next_attempt_at = NULL, claimed_at = NULL, last_error = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?'
                : 'UPDATE service_inbound_move SET claimed_at = NULL, last_error = ?, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
            null === $error ? [self::now(), $moveId, ServiceInboundMove::STATE_UNDONE, $claimedAt] : [$error, self::now(), $moveId, ServiceInboundMove::STATE_UNDONE, $claimedAt],
        );
        $refresh = $this->refreshLinks($service);
        $links = '; links '.self::refreshText($refresh);
        if (null !== $error) {
            $this->logger->warning('inbound_move_undo_detach_failed', ['run_id' => (int) $move['run_id'], 'service_id' => $serviceId, 'error' => $error]);

            return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::FAILED, 'back on the old inbound, but the new inbound could not be removed yet: '.$error.' (run --undo again)'.$links, InboundDetachResult::FAILED_UNREADABLE !== $result?->reason, false, $refresh->outcome);
        }

        return new InboundMoveItem($moveId, $serviceId, $from, $to, InboundMoveItem::UNDONE, 'back on the old inbound; the new inbound removed'.$links, true, false, $refresh->outcome);
    }

    /** An undo claim given back before its write: the previous state again, the error stored masked. */
    private function releaseUndo(int $serviceId, int $moveId, string $claimedAt, string $previous, string $error): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $previous, $error): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, claimed_at = NULL, last_error = ?, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [$previous, VpnServiceInbound::safeError($error), self::now(), $moveId, ServiceInboundMove::STATE_DETACHING, $claimedAt],
            );
        });
    }

    /**
     * Planned moves, and phase-1 failures waiting for a retry: cancelled (never moved now). An add's failed attach may
     * have landed, so undo takes that one off instead (see undoMoveIds()).
     */
    private function cancelNotStarted(int $runId, bool $add): int
    {
        if ($add) {
            return (int) $this->db()->executeStatement(
                "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE run_id = ? AND state = 'planned'",
                [ServiceInboundMove::STATE_CANCELLED, self::now(), $runId],
            );
        }

        return (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ?
             WHERE run_id = ? AND (state = 'planned' OR (state = 'failed' AND detach_after IS NULL AND next_attempt_at IS NOT NULL))",
            [ServiceInboundMove::STATE_CANCELLED, self::now(), $runId],
        );
    }

    /**
     * The moves --undo looks at, after $afterId: switched, a failed retire, a stale detaching claim, or undone with B
     * still to be removed. For an add: whatever may have put C on the client (switched, done, failed, a stale claim), or
     * undone with its links still owed; never a client that had C before the run.
     *
     * @return list<int>
     */
    private function undoMoveIds(int $runId, int $afterId, int $limit, bool $add): array
    {
        $stale = (new \DateTimeImmutable())->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');
        if ($add) {
            return array_map('intval', $this->db()->fetchFirstColumn(
                sprintf(
                    "SELECT m.id FROM service_inbound_move m WHERE m.run_id = :run AND m.id > :after AND COALESCE(m.skip_reason, '') <> :on_panel AND (
                       m.state IN ('switched', 'done', 'failed')
                       OR (m.state IN ('attaching', 'detaching') AND (m.claimed_at IS NULL OR m.claimed_at < :stale))
                       OR (m.state = 'undone' AND m.next_attempt_at IS NOT NULL AND (m.claimed_at IS NULL OR m.claimed_at < :stale)))
                     ORDER BY m.id LIMIT %d",
                    max(1, $limit),
                ),
                ['run' => $runId, 'after' => $afterId, 'on_panel' => InboundMovePlanner::ALREADY_ON_PANEL, 'stale' => $stale],
            ));
        }

        return array_map('intval', $this->db()->fetchFirstColumn(
            sprintf(
                "SELECT m.id FROM service_inbound_move m WHERE m.run_id = :run AND m.id > :after AND (
                   m.state = 'switched'
                   OR (m.state = 'failed' AND m.detach_after IS NOT NULL)
                   OR (m.state = 'detaching' AND (m.claimed_at IS NULL OR m.claimed_at < :stale))
                   OR (m.state = 'undone' AND m.next_attempt_at IS NOT NULL AND (m.claimed_at IS NULL OR m.claimed_at < :stale)))
                 ORDER BY m.id LIMIT %d",
                max(1, $limit),
            ),
            ['run' => $runId, 'after' => $afterId, 'stale' => $stale],
        ));
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
     * only while the move still holds this claim. The error is stored masked. $heldRow: an add's claimed row, given back.
     *
     * @return string the masked error
     */
    private function fail(int $serviceId, int $moveId, string $claimedAt, int $attempts, string $error, string $claimedState = ServiceInboundMove::STATE_ATTACHING, ?int $heldRow = null): string
    {
        $error = VpnServiceInbound::safeError($error);
        $next = $this->retries->nextAttemptAt($attempts, new \DateTimeImmutable())?->format('Y-m-d H:i:s');
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $error, $next, $claimedState, $heldRow): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            self::releaseRow($db, $heldRow, $claimedAt);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, last_error = ?, next_attempt_at = ?, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [ServiceInboundMove::STATE_FAILED, $error, $next, self::now(), $moveId, $claimedState, $claimedAt],
            );
        });

        return null === $next ? $error.' (attempts used up: given up)' : $error.' (next try after '.$next.')';
    }

    /** A claimed move ends without a panel write: skipped with the reason (same lock order, only under this claim). */
    private function settleClaim(int $serviceId, int $moveId, string $claimedAt, string $claimedState, string $state, string $reason, ?int $heldRow = null): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $claimedState, $state, $reason, $heldRow): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            self::releaseRow($db, $heldRow, $claimedAt);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, skip_reason = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [$state, $reason, self::now(), $moveId, $claimedState, $claimedAt],
            );
        });
    }

    /**
     * Whether a move row (as locked) may be claimed for phase 1 now: planned; attaching with a stale claim (a process
     * died); failed in phase 1 (detach_after NULL) and due with attempts left.
     *
     * @param array<string, mixed> $row
     */
    private function due(array $row, \DateTimeImmutable $now): bool
    {
        $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');

        return match ($row['state']) {
            // A primary move has no detach_after yet; a remove waits for it (docs/design/plan-inbound-add-remove.md 3.2).
            ServiceInboundMove::STATE_PLANNED => null === $row['detach_after'] || (string) $row['detach_after'] <= $now->format('Y-m-d H:i:s'),
            ServiceInboundMove::STATE_ATTACHING => null === $row['claimed_at'] || (string) $row['claimed_at'] < $stale,
            ServiceInboundMove::STATE_FAILED => null === $row['detach_after'] && $this->failedDue($row, $now),
            default => false,
        };
    }

    /**
     * Whether a move row (as locked) may be claimed for the retire now: switched with detach_after passed; detaching
     * with a stale claim; a failed retire (detach_after set) due with attempts left.
     *
     * @param array<string, mixed> $row
     */
    private function retireDue(array $row, \DateTimeImmutable $now): bool
    {
        $stale = $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');

        return match ($row['state']) {
            ServiceInboundMove::STATE_SWITCHED => null !== $row['detach_after'] && (string) $row['detach_after'] <= $now->format('Y-m-d H:i:s'),
            ServiceInboundMove::STATE_DETACHING => null === $row['claimed_at'] || (string) $row['claimed_at'] < $stale,
            ServiceInboundMove::STATE_FAILED => null !== $row['detach_after'] && $this->failedDue($row, $now),
            default => false,
        };
    }

    /** @param array<string, mixed> $row */
    private function failedDue(array $row, \DateTimeImmutable $now): bool
    {
        return null !== $row['next_attempt_at'] && (string) $row['next_attempt_at'] <= $now->format('Y-m-d H:i:s') && (int) $row['attempts'] < $this->retries->maxAttempts();
    }

    /**
     * Whether a move (as read, unlocked) belongs to phase 2 now: detaching, a failed retire, or switched and due.
     *
     * @param array<string, mixed> $move
     */
    private static function isRetire(array $move, string $now): bool
    {
        return ServiceInboundMove::STATE_DETACHING === $move['state']
            || (ServiceInboundMove::STATE_FAILED === $move['state'] && null !== $move['detach_after'])
            || (ServiceInboundMove::STATE_SWITCHED === $move['state'] && null !== $move['detach_after'] && (string) $move['detach_after'] <= $now);
    }

    /**
     * The run's moves this pass looks at, after $afterId: due for a claim (see due() and retireDue()), or moved with links
     * not refreshed since.
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
                   (m.state = 'planned' AND (m.detach_after IS NULL OR m.detach_after <= :now))
                   OR (m.state IN ('attaching', 'detaching') AND (m.claimed_at IS NULL OR m.claimed_at < :stale))
                   OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL AND m.next_attempt_at <= :now AND m.attempts < :max)
                   OR (m.state = 'switched' AND m.detach_after IS NOT NULL AND m.detach_after <= :now)
                   OR (m.state IN ('switched', 'done') AND %s)
                   OR %s)
                 ORDER BY m.id LIMIT %d",
                self::LINKS_PENDING_SQL,
                self::ADD_LINKS_DUE_SQL,
                max(1, $limit),
            ),
            ['run' => $runId, 'after' => $afterId, 'stale' => $now->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s'), 'max' => $this->retries->maxAttempts()],
        ));
    }

    /**
     * A moved service whose links were not refreshed since (the display guard's condition, 5.5). The stamps have one-second
     * precision: every write of inbound_changed_at here also clears last_access_info_synced_at, so that "same second" can
     * never read as "already refreshed".
     */
    private const LINKS_PENDING_SQL = 's.inbound_changed_at IS NOT NULL AND (s.last_access_info_synced_at IS NULL OR s.last_access_info_synced_at < s.inbound_changed_at)';

    /** Counters, the given-up alerts (one per service) and what is pending, after a pass. */
    private function finishPass(int $runId, InboundMovePass $pass): void
    {
        $this->planner->refreshCounts($runId);
        $pass->alertsSent += $this->alertGivenUp($runId);
        $add = InboundMoveRun::KIND_ADD === $pass->kind;
        $pass->snapshotsPending = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND m.state IN ('switched', 'done') AND (".self::LINKS_PENDING_SQL.($add ? " OR m.state = 'switched'" : '').')',
            [$runId],
        );
        $later = $this->db()->fetchAssociative(
            "SELECT COUNT(*) AS n, MIN(detach_after) AS first FROM service_inbound_move WHERE run_id = ? AND state = 'switched' AND detach_after IS NOT NULL",
            [$runId],
        ) ?: [];
        $pass->retirePending = (int) ($later['n'] ?? 0);
        $pass->retireNextAt = null === ($later['first'] ?? null) ? null : (string) $later['first'];
        $pass->keptOld = $add ? 0 : (int) $this->db()->fetchOne("SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state = 'switched' AND detach_after IS NULL", [$runId]);
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
     * The run is done when nothing in it can still change: no planned, attaching or detaching move, no failed move with
     * an attempt left, no switched service waiting for the removal of its old inbound or for its links, no retired one
     * waiting for its links. The transition is one conditional UPDATE, so with two runners only one finishes it: the
     * finished audit entry and the alert (when moves failed) are sent once.
     */
    private function finishRun(int $runId, int $planId, InboundMovePass $pass): void
    {
        $finished = $this->db()->executeStatement(
            sprintf(
                "UPDATE inbound_move_run SET status = ?, open_plan_id = NULL, finished_at = ? WHERE id = ? AND status = ? AND NOT EXISTS (
                   SELECT 1 FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND (
                     m.state IN ('planned', 'attaching', 'detaching')
                     OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL AND m.attempts < ?)
                     OR (m.state = 'switched' AND m.detach_after IS NOT NULL)
                     OR (m.state IN ('switched', 'done') AND %s)
                     OR %s))",
                self::LINKS_PENDING_SQL,
                self::ADD_LINKS_DUE_SQL,
            ),
            [InboundMoveRun::STATUS_DONE, self::now(), $runId, InboundMoveRun::STATUS_RUNNING, $runId, $this->retries->maxAttempts()],
        );
        if (1 !== $finished) {
            return;
        }
        $this->finishPass($runId, $pass);
        $this->auditLogger->record('plan.inbounds_apply_finished', 'plan', $planId, ['run_id' => $runId] + $this->planner->runDetails($runId) + $pass->counts);
        $this->logger->info('inbound_move_run_finished', ['run_id' => $runId, 'plan_id' => $planId] + $pass->counts);
        if ($pass->counts['failed'] > 0 && $this->alerts->moveRunEnded($runId, $planId, $pass->counts, null)) {
            ++$pass->alertsSent;
        }
    }

    /**
     * Q7: the plan no longer sells an add's target while work has not started (planned, or a failure with an attempt left).
     * What is left over from before (links, a step cut off) adds nothing new and goes on, so a cancelled run can finish.
     * A target deleted meanwhile is not this case: each move skips itself (target_gone).
     */
    private function planDropped(int $runId, int $planId, mixed $targetId): bool
    {
        if (null === $targetId) {
            return false;
        }
        $planInbound = $this->db()->fetchOne('SELECT inbound_id FROM plan WHERE id = ?', [$planId]);
        if (!InboundMoveOverview::planChanged(InboundMoveRun::KIND_ADD, (int) $targetId, null === $planInbound || false === $planInbound ? null : (int) $planInbound, $this->planner->planAdditionalIds($planId))) {
            return false;
        }

        return false !== $this->db()->fetchOne(
            "SELECT id FROM service_inbound_move WHERE run_id = ? AND (state = 'planned' OR (state = 'failed' AND next_attempt_at IS NOT NULL AND attempts < ?)) LIMIT 1",
            [$runId, $this->retries->maxAttempts()],
        );
    }

    /** running → paused (5.4), with one alert and one audit entry from the process that paused it. */
    private function pause(int $runId, int $planId, string $reason, InboundMovePass $pass): void
    {
        $paused = $this->db()->executeStatement(
            'UPDATE inbound_move_run SET status = ?, pause_reason = ? WHERE id = ? AND status = ?',
            [InboundMoveRun::STATUS_PAUSED, $reason, $runId, InboundMoveRun::STATUS_RUNNING],
        );
        if (1 === $paused) {
            $this->planner->refreshCounts($runId);
            $this->logger->error('inbound_move_run_paused', ['run_id' => $runId, 'plan_id' => $planId, 'reason' => $reason]);
            $counts = array_map('intval', $this->db()->fetchAssociative('SELECT planned_count AS planned, done_count AS done, failed_count AS failed, skipped_count AS skipped, remaining_count AS remaining FROM inbound_move_run WHERE id = ?', [$runId]) ?: []);
            $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $planId, ['run_id' => $runId, 'by' => 'automatic'] + $this->planner->runDetails($runId) + $counts);
            if ($this->alerts->moveRunEnded($runId, $planId, $counts, $reason)) {
                ++$pass->alertsSent;
            }
        }
    }

    private function stillRunning(int $runId): bool
    {
        return InboundMoveRun::STATUS_RUNNING === $this->db()->fetchOne('SELECT status FROM inbound_move_run WHERE id = ?', [$runId]);
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
            case InboundMoveItem::ADDED:
                ++$pass->added;
                $pass->alreadyOnTarget += $item->attachSent ? 0 : 1;
                break;
            case InboundMoveItem::FAILED:
                ++$pass->failed;
                break;
            case InboundMoveItem::SKIPPED:
                ++$pass->skipped;
                break;
            case InboundMoveItem::RETIRED:
                ++$pass->retired;
                $pass->snapshotsRefreshed += in_array($item->snapshot, [PanelLinkRefresh::UPDATED, PanelLinkRefresh::UNCHANGED, PanelLinkRefresh::CHANGED_MEANWHILE], true) ? 1 : 0;
                break;
            case InboundMoveItem::REATTACHED:
                ++$pass->reattached;
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
        $kind = $this->db()->fetchOne('SELECT kind FROM inbound_move_run WHERE id = ?', [(int) $move['run_id']]);
        $title = function (mixed $id, mixed $remote): string {
            if (null === $id) {
                return sprintf('(deleted inbound, panel id %s)', null === $remote ? '?' : (string) $remote);
            }
            $title = $this->db()->fetchOne('SELECT title FROM vpn_inbound WHERE id = ?', [(int) $id]);

            return sprintf('"%s" (#%d)', mb_substr((string) $title, 0, 60), (int) $id);
        };

        // An add has no old inbound and a remove no new one (docs/design/plan-inbound-add-remove.md 3.2).
        return [
            InboundMoveRun::KIND_ADD === $kind ? '-' : $title($move['from_inbound_id'], $move['from_remote_inbound_id']),
            InboundMoveRun::KIND_REMOVE === $kind ? '-' : $title($move['to_inbound_id'], $move['to_remote_inbound_id']),
        ];
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
     * @param list<string|null> $identity
     */
    private function identityChanged(int $serviceId, array $identity): bool
    {
        $current = $this->db()->fetchAssociative('SELECT inbound_id, remote_id, client_uuid, sub_id FROM vpn_service WHERE id = ?', [$serviceId]);

        return false === $current || self::identity($current) !== $identity;
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

    /** Never a panel call under someone's locks (5.3, as ServiceInboundAttacher::afterSale()). */
    private static function assertNoTransaction(Connection $db): void
    {
        if ($db->isTransactionActive()) {
            throw new \LogicException('An inbound move step must not run inside a transaction.');
        }
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
