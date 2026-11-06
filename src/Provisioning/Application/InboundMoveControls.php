<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\ServiceInboundMove;
use App\Security\AdminAuditLogger;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The run controls of docs/design/plan-inbound-change.md 5.4 that need no panel: pause, cancel, "retire now" (PR P6,
 * command line), and resume and "retry failed now" (PR P7, the web page: the scheduled task of P8 then does the work).
 * Each is one or two conditional autocommit UPDATEs on the run and its move rows: no service row is locked, no panel is
 * called, and a runner working on the run notices the change between two services (it finishes the one in hand). Undo
 * calls the panel and is InboundMoveRunner::undo().
 *
 * Every result text is fixed, with ids and counts only.
 */
final class InboundMoveControls
{
    public const PAUSED_BY_ADMIN = 'paused by an admin (--pause-run)';
    public const REOPENED = 'reopened for --retire-now: continue with --resume';

    /** A move that is not finished (InboundMovePlanner's rule, on alias o): its service is held by that run meanwhile. */
    private const UNFINISHED_SQL = "(o.state IN ('planned', 'attaching', 'detaching') OR (o.state = 'switched' AND o.detach_after IS NOT NULL) OR (o.state = 'failed' AND o.next_attempt_at IS NOT NULL))";

    /**
     * On alias m: a failed move that "retry failed now" takes: not a service in an unfinished move of another run or moved
     * again by a later one, and not an add whose undo stopped holding the row. The pages show their buttons by this rule.
     */
    public const RETRYABLE_SQL = "(m.state = 'failed' AND NOT EXISTS (SELECT 1 FROM service_inbound_move o WHERE o.service_id = m.service_id AND o.id <> m.id AND (o.id > m.id OR ".self::UNFINISHED_SQL.')) AND NOT '.InboundMoveRunner::ADD_UNDO_HELD_SQL.')';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMovePlanner $planner,
        private readonly InboundAttachAlerts $alerts,
        private readonly AdminAuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * planned|running → paused. A runner busy with the run finishes the service in hand and takes no other. One audit
     * entry and one alert (5.4: one per run when it pauses).
     *
     * @return array{ok: bool, message: string}
     */
    public function pause(int $runId): array
    {
        $run = $this->run($runId);
        if (null === $run) {
            return ['ok' => false, 'message' => sprintf('There is no run #%d.', $runId)];
        }
        $paused = $this->db()->executeStatement(
            'UPDATE inbound_move_run SET status = ?, pause_reason = ? WHERE id = ? AND status IN (?, ?)',
            [InboundMoveRun::STATUS_PAUSED, self::PAUSED_BY_ADMIN, $runId, InboundMoveRun::STATUS_PLANNED, InboundMoveRun::STATUS_RUNNING],
        );
        if (1 !== $paused) {
            return ['ok' => false, 'message' => sprintf('Run #%d is %s: only a planned or running run can be paused.', $runId, $run['status'])];
        }
        $counts = $this->counts($runId);
        $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $run['plan_id'], ['run_id' => $runId, 'by' => 'admin'] + $this->planner->runDetails($runId) + $counts);
        $this->logger->warning('inbound_move_run_paused', ['run_id' => $runId, 'plan_id' => $run['plan_id'], 'reason' => self::PAUSED_BY_ADMIN]);
        $this->alerts->moveRunEnded($runId, $run['plan_id'], $counts, self::PAUSED_BY_ADMIN);

        return ['ok' => true, 'message' => sprintf('Run #%d is paused. A pass working on it finishes the service in hand and takes no other. Continue with --resume=%d.', $runId, $runId)];
    }

    /**
     * Cancel (5.4): planned moves, and phase-1 failures waiting for a retry, become "cancelled"; moves in another
     * process's hands finish their current step. Moved services whose old inbound is still to be removed keep the run
     * open for that (their retire, or --undo); otherwise the run is "cancelled".
     *
     * @return array{ok: bool, message: string, cancelled: int, open: bool}
     */
    public function cancel(int $runId): array
    {
        $run = $this->run($runId);
        if (null === $run) {
            return ['ok' => false, 'message' => sprintf('There is no run #%d.', $runId), 'cancelled' => 0, 'open' => false];
        }
        if (!in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true)) {
            return ['ok' => false, 'message' => sprintf('Run #%d is %s: nothing to cancel.', $runId, $run['status']), 'cancelled' => 0, 'open' => false];
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $remove = InboundMoveRun::KIND_REMOVE === $run['kind'];
        if ($remove) {
            // A planned move that holds its row is not "not started": see cancelNotStarted() of the runner. Read first: this
            // UPDATE must not touch the rows of vpn_service_inbound against the lock order.
            $ids = array_map('intval', $this->db()->fetchFirstColumn("SELECT m.id FROM service_inbound_move m WHERE m.run_id = ? AND m.state = 'planned' AND NOT ".InboundMoveRunner::REMOVE_HELD_SQL, [$runId]));
            $cancelled = [] === $ids ? 0 : (int) $this->db()->executeStatement(
                "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ? WHERE state = 'planned' AND id IN (?)",
                [ServiceInboundMove::STATE_CANCELLED, $now, $ids],
                [2 => ArrayParameterType::INTEGER],
            );
        } else {
            $cancelled = (int) $this->db()->executeStatement(
                "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ?
                 WHERE run_id = ? AND (state = 'planned' OR (state = 'failed' AND detach_after IS NULL AND next_attempt_at IS NOT NULL))",
                [ServiceInboundMove::STATE_CANCELLED, $now, $runId],
            );
        }
        $left = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND (
               m.state IN ('attaching', 'detaching')
               OR (m.state = 'switched' AND m.detach_after IS NOT NULL)
               OR (m.state = 'failed' AND m.detach_after IS NOT NULL AND m.next_attempt_at IS NOT NULL)
               OR (m.state IN ('switched', 'done') AND s.inbound_changed_at IS NOT NULL AND (s.last_access_info_synced_at IS NULL OR s.last_access_info_synced_at < s.inbound_changed_at))
               OR ".InboundMoveRunner::ADD_LINKS_DUE_SQL.($remove ? " OR (m.state = 'planned' AND ".InboundMoveRunner::REMOVE_HELD_SQL.')' : '').')',
            [$runId],
        );
        if (0 === $left) {
            $this->db()->executeStatement(
                'UPDATE inbound_move_run SET status = ?, open_plan_id = NULL, pause_reason = NULL, finished_at = ? WHERE id = ? AND status IN (?, ?, ?)',
                [InboundMoveRun::STATUS_CANCELLED, $now, $runId, ...InboundMoveRun::OPEN_STATUSES],
            );
        }
        $counts = $this->counts($runId);
        $this->auditLogger->record('plan.inbounds_apply_cancelled', 'plan', $run['plan_id'], ['run_id' => $runId, 'cancelled' => $cancelled, 'still_open' => $left > 0] + $this->planner->runDetails($runId) + $counts);
        $this->logger->info('inbound_move_run_cancelled', ['run_id' => $runId, 'plan_id' => $run['plan_id'], 'cancelled' => $cancelled, 'still_open' => $left > 0]);

        if ($remove) {
            return [
                'ok' => true,
                'message' => 0 === $left
                    ? sprintf('Run #%d is cancelled: %d service(s) not reached yet keep the inbound.', $runId, $cancelled)
                    : sprintf('Run #%d: %d service(s) not reached yet keep the inbound. %d service(s) were started (failed, in the middle of a step, or waiting for their links): the run stays open for that; continue with --resume=%d, or put the inbound back with --undo=%d.', $runId, $cancelled, $left, $runId, $runId),
                'cancelled' => $cancelled,
                'open' => $left > 0,
            ];
        }
        if (InboundMoveRun::KIND_ADD === $run['kind']) {
            return [
                'ok' => true,
                'message' => 0 === $left
                    ? sprintf('Run #%d is cancelled: %d service(s) not reached yet will not get the inbound.', $runId, $cancelled)
                    : sprintf('Run #%d: %d service(s) not reached yet will not get the inbound. %d service(s) still wait for their links (or are in the middle of a step): the run stays open for that; continue with --resume=%d, or take the inbound off with --undo=%d.', $runId, $cancelled, $left, $runId, $runId),
                'cancelled' => $cancelled,
                'open' => $left > 0,
            ];
        }

        return [
            'ok' => true,
            'message' => 0 === $left
                ? sprintf('Run #%d is cancelled: %d service(s) not moved yet will not be moved.', $runId, $cancelled)
                : sprintf('Run #%d: %d service(s) not moved yet will not be moved. %d moved service(s) still wait for their old inbound to be removed (or are in the middle of a step): the run stays open for that; continue with --resume=%d, or put them back with --undo=%d.', $runId, $cancelled, $left, $runId, $runId),
            'cancelled' => $cancelled,
            'open' => $left > 0,
        ];
    }

    /**
     * "Retire now": detach_after = now for the run's switched moves (one service, or all), and failed retires are armed
     * again (attempts from zero). The old inbound is then removed by the next pass of the run (--resume). A finished run
     * (a --keep-old run is finished right after phase 1) is reopened, paused, unless the plan has another open run.
     *
     * @return array{ok: bool, message: string, due: int}
     */
    public function retireNow(int $runId, ?int $serviceId = null): array
    {
        $run = $this->run($runId);
        if (null === $run) {
            return ['ok' => false, 'message' => sprintf('There is no run #%d.', $runId), 'due' => 0];
        }
        if (InboundMoveRun::KIND_ADD === $run['kind']) {
            // Never a detach_after on an add's move: the runner would read it as a retire of the service's primary.
            return ['ok' => false, 'message' => sprintf('Run #%d adds an inbound: --retire-now is only for a move.', $runId), 'due' => 0];
        }
        $reopen = !in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true);
        if ($reopen) {
            $open = $this->planner->openRunId($run['plan_id']);
            if (null !== $open) {
                return ['ok' => false, 'message' => sprintf('Run #%d is %s and the plan has another open run (#%d): finish or cancel that one first.', $runId, $run['status'], $open), 'due' => 0];
            }
        }
        $remove = InboundMoveRun::KIND_REMOVE === $run['kind'];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $only = null === $serviceId ? '' : ' AND service_id = '.(int) $serviceId;
        $due = (int) $this->db()->executeStatement(
            // A remove waits in "planned" for its detach_after (docs/design/plan-inbound-add-remove.md 3.2); a primary
            // move in "planned" has none.
            "UPDATE service_inbound_move SET detach_after = ?, updated_at = ? WHERE run_id = ? AND (state = 'switched' OR (state = 'planned' AND detach_after IS NOT NULL))".$only,
            [$now, $now, $runId],
        );
        $due += (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET state = ?, attempts = 0, next_attempt_at = NULL, detach_after = ?, alert_state = NULL, alerted_at = NULL, updated_at = ?
             WHERE run_id = ? AND state = 'failed' AND detach_after IS NOT NULL".$only,
            [$remove ? ServiceInboundMove::STATE_PLANNED : ServiceInboundMove::STATE_SWITCHED, $now, $now, $runId],
        );
        if (0 === $due) {
            return ['ok' => false, 'message' => sprintf($remove ? 'Run #%d has no service%s waiting for the removal of the inbound.' : 'Run #%d has no moved service%s whose old inbound is still attached.', $runId, null === $serviceId ? '' : ' #'.$serviceId), 'due' => 0];
        }
        if ($reopen) {
            try {
                $this->db()->executeStatement(
                    'UPDATE inbound_move_run SET status = ?, open_plan_id = plan_id, pause_reason = ?, finished_at = NULL WHERE id = ? AND status IN (?, ?)',
                    [InboundMoveRun::STATUS_PAUSED, self::REOPENED, $runId, InboundMoveRun::STATUS_DONE, InboundMoveRun::STATUS_CANCELLED],
                );
            } catch (UniqueConstraintViolationException) {
                return ['ok' => false, 'message' => sprintf('Run #%d could not be reopened: the plan has another open run meanwhile. %d service(s) are due; they are retired when run #%d is resumed.', $runId, $due, $runId), 'due' => $due];
            }
        }
        $this->planner->refreshCounts($runId);
        $this->logger->info('inbound_move_retire_now', ['run_id' => $runId, 'service_id' => $serviceId, 'due' => $due, 'reopened' => $reopen]);

        return ['ok' => true, 'message' => sprintf($remove ? '%d service(s) of run #%d are due for the removal of the inbound now%s.' : '%d moved service(s) of run #%d are due for the removal of their old inbound now%s.', $due, $runId, $reopen ? ' (the finished run was reopened, paused)' : ''), 'due' => $due];
    }

    /**
     * paused → running, without a pass (the web's "Resume", PR P7): the scheduled task continues the run at its next
     * pass. The command line's --resume makes the pass itself (InboundMoveRunner::process()), which sets the same
     * status. Refused while the switch is off, as --resume.
     *
     * @return array{ok: bool, message: string}
     */
    public function resume(int $runId, bool $enabled): array
    {
        $run = $this->run($runId);
        if (null === $run) {
            return ['ok' => false, 'message' => sprintf('There is no run #%d.', $runId)];
        }
        if (!$enabled) {
            return ['ok' => false, 'message' => 'Moving services is switched off: the run is not continued.'];
        }
        $resumed = $this->db()->executeStatement(
            'UPDATE inbound_move_run SET status = ?, pause_reason = NULL, started_at = COALESCE(started_at, ?) WHERE id = ? AND status = ?',
            [InboundMoveRun::STATUS_RUNNING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $runId, InboundMoveRun::STATUS_PAUSED],
        );
        if (1 !== $resumed) {
            return ['ok' => false, 'message' => sprintf('Run #%d is %s: only a paused run can be resumed.', $runId, $run['status'])];
        }
        $this->logger->info('inbound_move_run_resumed', ['run_id' => $runId, 'plan_id' => $run['plan_id']]);

        return ['ok' => true, 'message' => sprintf('Run #%d continues at the next pass of the scheduled task.', $runId)];
    }

    /**
     * "Retry failed now" (5.6, the web's run and service buttons, PR P7): the run's failed moves (one service's, or
     * all) are due at once, their attempts from zero and their alert reset, as --retire-now does for a failed retire.
     * The next pass (the scheduled task, or --resume) tries them; no panel is called here. A move whose service is in an
     * unfinished move of another run, or was moved again by a later run, is left alone, and so is an add whose undo
     * stopped holding the row (RETRYABLE_SQL). A failed remove keeps its held row and starts with a read, like its
     * back-off. A done run is reopened as running unless the plan has another open run; a cancelled run is not retried
     * (it was stopped on purpose); a paused run stays paused. Adding and removing also need plans.multi_inbound_enabled:
     * the pages refuse without it, since a pass would do nothing.
     *
     * @return array{ok: bool, message: string, due: int, reopened: bool}
     */
    public function retryFailed(int $runId, bool $enabled, ?int $serviceId = null): array
    {
        $run = $this->run($runId);
        if (null === $run) {
            return ['ok' => false, 'message' => sprintf('There is no run #%d.', $runId), 'due' => 0, 'reopened' => false];
        }
        if (!$enabled) {
            return ['ok' => false, 'message' => 'Moving services is switched off: nothing is retried.', 'due' => 0, 'reopened' => false];
        }
        if (InboundMoveRun::STATUS_CANCELLED === $run['status']) {
            return ['ok' => false, 'message' => sprintf('Run #%d is cancelled: its failed services are not retried.', $runId), 'due' => 0, 'reopened' => false];
        }
        $reopen = InboundMoveRun::STATUS_DONE === $run['status'];
        if ($reopen && null !== ($open = $this->planner->openRunId($run['plan_id']))) {
            return ['ok' => false, 'message' => sprintf('Run #%d is done and the plan has another open run (#%d): finish or cancel that one first.', $runId, $open), 'due' => 0, 'reopened' => false];
        }
        $ids = array_map('intval', $this->db()->fetchFirstColumn(
            'SELECT m.id FROM service_inbound_move m WHERE m.run_id = ?'.(null === $serviceId ? '' : ' AND m.service_id = '.(int) $serviceId).' AND '.self::RETRYABLE_SQL.' ORDER BY m.id',
            [$runId],
        ));
        if ([] === $ids) {
            return ['ok' => false, 'message' => sprintf('Run #%d has no failed service%s to retry.', $runId, null === $serviceId ? '' : ' #'.$serviceId), 'due' => 0, 'reopened' => false];
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $due = (int) $this->db()->executeStatement(
            sprintf('UPDATE service_inbound_move SET attempts = 0, next_attempt_at = ?, alert_state = NULL, alerted_at = NULL, updated_at = ? WHERE state = ? AND id IN (%s)', implode(', ', $ids)),
            [$now, $now, ServiceInboundMove::STATE_FAILED],
        );
        $reopened = false;
        if ($reopen && $due > 0) {
            try {
                $reopened = 1 === $this->db()->executeStatement(
                    'UPDATE inbound_move_run SET status = ?, open_plan_id = plan_id, pause_reason = NULL, finished_at = NULL WHERE id = ? AND status = ?',
                    [InboundMoveRun::STATUS_RUNNING, $runId, InboundMoveRun::STATUS_DONE],
                );
            } catch (UniqueConstraintViolationException) {
                return ['ok' => false, 'message' => sprintf('Run #%d could not be reopened: the plan has another open run meanwhile.', $runId), 'due' => $due, 'reopened' => false];
            }
        }
        $this->planner->refreshCounts($runId);
        $this->logger->info('inbound_move_retry_now', ['run_id' => $runId, 'service_id' => $serviceId, 'due' => $due, 'reopened' => $reopened]);

        return ['ok' => true, 'message' => sprintf('%d failed service(s) of run #%d are due again now.', $due, $runId), 'due' => $due, 'reopened' => $reopened];
    }

    /**
     * What an undo says about the moves whose old inbound was already removed (5.4: undo cannot put them back): one
     * text for the command line and the web page.
     */
    public static function alreadyRetiredText(int $count, int $planId): string
    {
        return sprintf(
            'The old inbound was already removed from %d services: undo cannot put them back. That needs a new run from the new inbound back to the old one for these %d service(s): set the plan\'s inbound back to the old one, then run app:plan:apply-inbounds %d (dry run first). The old inbound must exist and be active.',
            $count, $count, $planId,
        );
    }

    /** @return array{plan_id: int, status: string, kind: string}|null */
    private function run(int $runId): ?array
    {
        $run = $this->db()->fetchAssociative('SELECT plan_id, status, kind FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $run || !in_array($run['kind'], InboundMoveRun::RUNNABLE_KINDS, true)) {
            return null;
        }

        return ['plan_id' => (int) $run['plan_id'], 'status' => (string) $run['status'], 'kind' => (string) $run['kind']];
    }

    /** @return array{planned: int, done: int, failed: int, skipped: int, remaining: int} */
    private function counts(int $runId): array
    {
        $this->planner->refreshCounts($runId);
        $row = $this->db()->fetchAssociative('SELECT planned_count, done_count, failed_count, skipped_count, remaining_count FROM inbound_move_run WHERE id = ?', [$runId]) ?: [];

        return [
            'planned' => (int) ($row['planned_count'] ?? 0),
            'done' => (int) ($row['done_count'] ?? 0),
            'failed' => (int) ($row['failed_count'] ?? 0),
            'skipped' => (int) ($row['skipped_count'] ?? 0),
            'remaining' => (int) ($row['remaining_count'] ?? 0),
        ];
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }
}
