<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\ServiceInboundMove;
use App\Security\AdminAuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The run controls of docs/design/plan-inbound-change.md 5.4 that need no panel (PR P6, command line only): pause,
 * cancel, "retire now". Each is one or two conditional autocommit UPDATEs on the run and its move rows: no service row
 * is locked, no panel is called, and a runner working on the run notices the change between two services (it finishes
 * the one in hand). Undo calls the panel and is InboundMoveRunner::undo().
 *
 * Every result text is fixed, with ids and counts only.
 */
final class InboundMoveControls
{
    public const PAUSED_BY_ADMIN = 'paused by an admin (--pause-run)';
    public const REOPENED = 'reopened for --retire-now: continue with --resume';

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
        $this->auditLogger->record('plan.inbounds_apply_paused', 'plan', $run['plan_id'], ['run_id' => $runId, 'by' => 'admin'] + $counts);
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
        $cancelled = (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET state = ?, claimed_at = NULL, next_attempt_at = NULL, updated_at = ?
             WHERE run_id = ? AND (state = 'planned' OR (state = 'failed' AND detach_after IS NULL AND next_attempt_at IS NOT NULL))",
            [ServiceInboundMove::STATE_CANCELLED, $now, $runId],
        );
        $left = (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id WHERE m.run_id = ? AND (
               m.state IN ('attaching', 'detaching')
               OR (m.state = 'switched' AND m.detach_after IS NOT NULL)
               OR (m.state = 'failed' AND m.detach_after IS NOT NULL AND m.next_attempt_at IS NOT NULL)
               OR (m.state IN ('switched', 'done') AND s.inbound_changed_at IS NOT NULL AND (s.last_access_info_synced_at IS NULL OR s.last_access_info_synced_at < s.inbound_changed_at)))",
            [$runId],
        );
        if (0 === $left) {
            $this->db()->executeStatement(
                'UPDATE inbound_move_run SET status = ?, open_plan_id = NULL, pause_reason = NULL, finished_at = ? WHERE id = ? AND status IN (?, ?, ?)',
                [InboundMoveRun::STATUS_CANCELLED, $now, $runId, ...InboundMoveRun::OPEN_STATUSES],
            );
        }
        $counts = $this->counts($runId);
        $this->auditLogger->record('plan.inbounds_apply_cancelled', 'plan', $run['plan_id'], ['run_id' => $runId, 'cancelled' => $cancelled, 'still_open' => $left > 0] + $counts);
        $this->logger->info('inbound_move_run_cancelled', ['run_id' => $runId, 'plan_id' => $run['plan_id'], 'cancelled' => $cancelled, 'still_open' => $left > 0]);

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
        $reopen = !in_array($run['status'], InboundMoveRun::OPEN_STATUSES, true);
        if ($reopen) {
            $open = $this->planner->openRunId($run['plan_id']);
            if (null !== $open) {
                return ['ok' => false, 'message' => sprintf('Run #%d is %s and the plan has another open run (#%d): finish or cancel that one first.', $runId, $run['status'], $open), 'due' => 0];
            }
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $only = null === $serviceId ? '' : ' AND service_id = '.(int) $serviceId;
        $due = (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET detach_after = ?, updated_at = ? WHERE run_id = ? AND state = 'switched'".$only,
            [$now, $now, $runId],
        );
        $due += (int) $this->db()->executeStatement(
            "UPDATE service_inbound_move SET state = ?, attempts = 0, next_attempt_at = NULL, detach_after = ?, alert_state = NULL, alerted_at = NULL, updated_at = ?
             WHERE run_id = ? AND state = 'failed' AND detach_after IS NOT NULL".$only,
            [ServiceInboundMove::STATE_SWITCHED, $now, $now, $runId],
        );
        if (0 === $due) {
            return ['ok' => false, 'message' => sprintf('Run #%d has no moved service%s whose old inbound is still attached.', $runId, null === $serviceId ? '' : ' #'.$serviceId), 'due' => 0];
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

        return ['ok' => true, 'message' => sprintf('%d moved service(s) of run #%d are due for the removal of their old inbound now%s.', $due, $runId, $reopen ? ' (the finished run was reopened, paused)' : ''), 'due' => $due];
    }

    /** @return array{plan_id: int, status: string}|null */
    private function run(int $runId): ?array
    {
        $run = $this->db()->fetchAssociative('SELECT plan_id, status, kind FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $run || InboundMoveRun::KIND_PRIMARY !== $run['kind']) {
            return null;
        }

        return ['plan_id' => (int) $run['plan_id'], 'status' => (string) $run['status']];
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
