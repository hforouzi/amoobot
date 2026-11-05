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

    private const CLAIMED = 'claimed';
    /** The claim found a reason not to go on (skipped, or an error for --undo): written under the locks, reported. */
    private const SETTLED = 'settled';
    private const NOT_DUE = 'not_due';
    private const KEPT = 'kept';

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
        $run = $this->db()->fetchAssociative('SELECT id, plan_id, kind, status, target_inbound_id, keep_old, grace_hours FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $run || !in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true) || InboundMoveRun::KIND_PRIMARY !== $run['kind']) {
            return new InboundMovePass(InboundMovePass::NOT_OPEN, false === $run ? sprintf('There is no run #%d.', $runId) : sprintf('Run #%d is %s: nothing to do.', $runId, (string) $run['status']));
        }
        $planId = (int) $run['plan_id'];
        $graceHours = 1 === (int) $run['keep_old'] ? null : max(0, (int) $run['grace_hours']);
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
                if (!$this->stillRunning($runId)) {
                    // Paused or cancelled meanwhile (--pause-run, --cancel, --undo): the item in hand was finished.
                    $this->finishPass($runId, $pass);

                    return $pass;
                }
                $lastId = $moveId;
                $item = $this->one($moveId, $planId, $scope, $runId, $graceHours);
                if (InboundMoveItem::IDENTITY_CHANGED === $item->outcome) {
                    // Picked again at once (4.3 step 3), for the credentials the service has now.
                    $item = $this->one($moveId, $planId, $scope, $runId, $graceHours);
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
        if (false === $run || InboundMoveRun::KIND_PRIMARY !== $run['kind']) {
            return new InboundMovePass(InboundMovePass::NOT_OPEN, sprintf('There is no run #%d to undo.', $runId));
        }
        $planId = (int) $run['plan_id'];
        $open = in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true);
        if ($open) {
            // No runner takes another service of this run meanwhile (it stops after the service in hand).
            $this->db()->executeStatement(
                'UPDATE inbound_move_run SET status = ?, pause_reason = ? WHERE id = ? AND status IN (?, ?, ?)',
                [InboundMoveRun::STATUS_PAUSED, self::UNDO_IN_PROGRESS, $runId, ...InboundMoveRun::OPEN_STATUSES],
            );
        }
        $pass = new InboundMovePass();
        $pass->cancelled = $this->cancelNotStarted($runId);

        $lastId = 0;
        $readFailures = 0;
        $pausedForPanel = null;
        while (null === $pausedForPanel) {
            $ids = $this->undoMoveIds($runId, $lastId, $this->settings->batchSize());
            if ([] === $ids) {
                break;
            }
            foreach ($ids as $moveId) {
                $lastId = $moveId;
                $item = $this->undoOne($moveId, $planId);
                if (InboundMoveItem::IDENTITY_CHANGED === $item->outcome) {
                    $item = $this->undoOne($moveId, $planId);
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
        $pass->alreadyRetired = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state = ?', [$runId, ServiceInboundMove::STATE_DONE]);
        $inFlight = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state IN ('attaching', 'detaching') AND claimed_at IS NOT NULL AND claimed_at >= ?",
            [$runId, $stale],
        );
        $pass->busy += $inFlight;
        $left = count($this->undoMoveIds($runId, 0, 1000));
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
        ]);
        $this->logger->info('inbound_move_run_undone', ['run_id' => $runId, 'plan_id' => $planId, 'undone' => $pass->undone, 'failed' => $pass->failed, 'already_retired' => $pass->alreadyRetired]);
        if (null !== $pausedForPanel) {
            $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $planId, ['run_id' => $runId, 'by' => 'automatic'] + $pass->counts);
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

    /** Planned moves, and phase-1 failures waiting for a retry: cancelled (never moved now). */
    private function cancelNotStarted(int $runId): int
    {
        return (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ?
             WHERE run_id = ? AND (state = 'planned' OR (state = 'failed' AND detach_after IS NULL AND next_attempt_at IS NOT NULL))",
            [ServiceInboundMove::STATE_CANCELLED, self::now(), $runId],
        );
    }

    /**
     * The moves --undo looks at, after $afterId: switched, a failed retire, a stale detaching claim, or undone with B
     * still to be removed.
     *
     * @return list<int>
     */
    private function undoMoveIds(int $runId, int $afterId, int $limit): array
    {
        $stale = (new \DateTimeImmutable())->modify(ServiceInboundAttacher::STALE_CLAIM)->format('Y-m-d H:i:s');

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
     * only while the move still holds this claim. The error is stored masked.
     *
     * @return string the masked error
     */
    private function fail(int $serviceId, int $moveId, string $claimedAt, int $attempts, string $error, string $claimedState = ServiceInboundMove::STATE_ATTACHING): string
    {
        $error = VpnServiceInbound::safeError($error);
        $next = $this->retries->nextAttemptAt($attempts, new \DateTimeImmutable())?->format('Y-m-d H:i:s');
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $error, $next, $claimedState): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $db->executeStatement(
                'UPDATE service_inbound_move SET state = ?, last_error = ?, next_attempt_at = ?, claimed_at = NULL, updated_at = ? WHERE id = ? AND state = ? AND claimed_at = ?',
                [ServiceInboundMove::STATE_FAILED, $error, $next, self::now(), $moveId, $claimedState, $claimedAt],
            );
        });

        return null === $next ? $error.' (attempts used up: given up)' : $error.' (next try after '.$next.')';
    }

    /** A claimed move ends without a panel write: skipped with the reason (same lock order, only under this claim). */
    private function settleClaim(int $serviceId, int $moveId, string $claimedAt, string $claimedState, string $state, string $reason): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $moveId, $claimedAt, $claimedState, $state, $reason): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $db->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
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
            ServiceInboundMove::STATE_PLANNED => true,
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
                   m.state = 'planned'
                   OR (m.state IN ('attaching', 'detaching') AND (m.claimed_at IS NULL OR m.claimed_at < :stale))
                   OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL AND m.next_attempt_at <= :now AND m.attempts < :max)
                   OR (m.state = 'switched' AND m.detach_after IS NOT NULL AND m.detach_after <= :now)
                   OR (m.state IN ('switched', 'done') AND %s))
                 ORDER BY m.id LIMIT %d",
                self::LINKS_PENDING_SQL,
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
        $pass->snapshotsPending = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND m.state IN ('switched', 'done') AND ".self::LINKS_PENDING_SQL,
            [$runId],
        );
        $later = $this->db()->fetchAssociative(
            "SELECT COUNT(*) AS n, MIN(detach_after) AS first FROM service_inbound_move WHERE run_id = ? AND state = 'switched' AND detach_after IS NOT NULL",
            [$runId],
        ) ?: [];
        $pass->retirePending = (int) ($later['n'] ?? 0);
        $pass->retireNextAt = null === ($later['first'] ?? null) ? null : (string) $later['first'];
        $pass->keptOld = (int) $this->db()->fetchOne("SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND state = 'switched' AND detach_after IS NULL", [$runId]);
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
                     OR (m.state IN ('switched', 'done') AND %s)))",
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
            $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $planId, ['run_id' => $runId, 'by' => 'automatic'] + $counts);
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
