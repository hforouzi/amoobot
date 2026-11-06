<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnServiceInbound;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the web pages show about inbound moves (docs/design/plan-inbound-change.md 5.6, PR P7): a plan's open or last
 * run, a service's latest move, and the counts of failed and given-up moves for the panel doctor. Read only: a few
 * fixed queries, no lock, no write, no panel call. Ids, inbound titles, states, counts and masked errors only: never a
 * link, UUID, subscription id, client email, token or owner (D5).
 *
 * A move is "given up" when it failed with no automatic retry left (next_attempt_at NULL: the attempts are used up or
 * the retry is off). Counts per service use the service's latest move only, so a service moved by a later run is not
 * counted for an old failure.
 */
final class InboundMoveOverview
{
    /** The latest move of each service (alias m; vpn_service s joined). */
    public const LATEST_SQL = 'm.id = (SELECT MAX(l.id) FROM service_inbound_move l WHERE l.service_id = m.service_id)';
    /** A move still on its way (alias m): not started, in a step, or moved with the old inbound still to be removed. */
    public const MOVING_SQL = "(m.state IN ('planned', 'attaching', 'detaching') OR (m.state = 'switched' AND m.detach_after IS NOT NULL) OR (m.state = 'undone' AND m.next_attempt_at IS NOT NULL))";

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The services lists' filter (web and Mini App), as a DQL condition on the list's own query (one query per page):
     * the service's latest move is on its way ("moving") or failed ("failed").
     */
    public static function listFilterDql(string $filter, string $serviceAlias): string
    {
        return sprintf(
            'EXISTS (SELECT listMove.id FROM %1$s listMove WHERE listMove.service = %3$s AND %2$s AND listMove.id = (SELECT MAX(listLatest.id) FROM %1$s listLatest WHERE listLatest.service = %3$s))',
            ServiceInboundMove::class,
            'failed' === $filter
                ? "listMove.state = 'failed'"
                : "(listMove.state IN ('planned', 'attaching', 'detaching') OR (listMove.state = 'switched' AND listMove.detachAfter IS NOT NULL) OR (listMove.state = 'undone' AND listMove.nextAttemptAt IS NOT NULL))",
            $serviceAlias,
        );
    }

    /**
     * The plan's open run, or else its latest one, with what the run block shows and which buttons apply now.
     *
     * @return array<string, mixed>|null
     */
    public function planRun(int $planId, ?int $planInboundId): ?array
    {
        $run = $this->db()->fetchAssociative(
            'SELECT r.*, t.title AS target_title, t.remote_inbound_id AS target_remote, a.email AS admin_email
             FROM inbound_move_run r LEFT JOIN vpn_inbound t ON t.id = r.target_inbound_id LEFT JOIN admin_user a ON a.id = r.requested_by
             WHERE r.plan_id = ? AND r.kind = ? ORDER BY (r.open_plan_id IS NULL), r.id DESC LIMIT 1',
            [$planId, InboundMoveRun::KIND_PRIMARY],
        );
        if (false === $run) {
            return null;
        }
        $runId = (int) $run['id'];
        $kind = (string) $run['kind'];
        $targetId = null === $run['target_inbound_id'] ? null : (int) $run['target_inbound_id'];
        // A remove waits in "planned" for its detach_after; a primary move in "planned" has none.
        $sum = $this->db()->fetchAssociative(
            sprintf("SELECT COALESCE(SUM(%s), 0) AS retired,
                    COALESCE(SUM(state IN ('switched', 'planned') AND detach_after IS NOT NULL), 0) AS waiting_retire,
                    MIN(CASE WHEN state IN ('switched', 'planned') THEN detach_after END) AS next_retire,
                    COALESCE(SUM(state = 'failed'), 0) AS failed,
                    COALESCE(SUM(state = 'failed' AND next_attempt_at IS NULL), 0) AS given_up,
                    COALESCE(SUM(state = 'undone'), 0) AS undone,
                    COALESCE(SUM(state = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(%s), 0) AS undoable,
                    COALESCE(SUM(state = 'planned' OR (state = 'failed' AND detach_after IS NULL AND next_attempt_at IS NOT NULL)), 0) AS not_started
             FROM service_inbound_move WHERE run_id = ?", self::retiredSql($kind), self::undoableSql($kind)),
            [$runId],
        ) ?: [];
        $skipped = [];
        foreach ($this->db()->fetchAllAssociative('SELECT skip_reason, COUNT(*) AS n FROM service_inbound_move WHERE run_id = ? AND state = ? GROUP BY skip_reason ORDER BY skip_reason', [$runId, ServiceInboundMove::STATE_SKIPPED]) as $row) {
            $skipped[(string) ($row['skip_reason'] ?? '')] = (int) $row['n'];
        }
        $int = static fn (string $key): int => (int) ($sum[$key] ?? 0);
        $status = (string) $run['status'];
        $open = in_array($status, InboundMoveRun::OPEN_STATUSES, true);

        return [
            'id' => $runId,
            'kind' => $kind,
            'status' => $status,
            'open' => $open,
            'pause_reason' => null === $run['pause_reason'] ? null : mb_substr((string) $run['pause_reason'], 0, 255),
            'target_id' => $targetId,
            'target_title' => null === $run['target_title'] ? null : mb_substr((string) $run['target_title'], 0, 60),
            'plan_changed' => self::planChanged($kind, $targetId, $planInboundId, InboundMoveRun::KIND_PRIMARY === $kind ? [] : $this->planAdditionalIds($planId)),
            'keep_old' => 1 === (int) $run['keep_old'],
            'grace_hours' => (int) $run['grace_hours'],
            'started_by' => null === $run['requested_by'] ? null : ['id' => (int) $run['requested_by'], 'email' => null === $run['admin_email'] ? null : (string) $run['admin_email']],
            'created_at' => (string) $run['created_at'],
            'started_at' => null === $run['started_at'] ? null : (string) $run['started_at'],
            'finished_at' => null === $run['finished_at'] ? null : (string) $run['finished_at'],
            'counts' => [
                'planned' => (int) $run['planned_count'],
                'done' => (int) $run['done_count'],
                'failed' => (int) $run['failed_count'],
                'skipped' => (int) $run['skipped_count'],
                'remaining' => (int) $run['remaining_count'],
            ],
            'skipped_by_reason' => $skipped,
            'given_up' => $int('given_up'),
            'retired' => $int('retired'),
            'waiting_retire' => $int('waiting_retire'),
            'next_retire_at' => null === ($sum['next_retire'] ?? null) ? null : (string) $sum['next_retire'],
            'undone' => $int('undone'),
            'cancelled' => $int('cancelled'),
            'can' => [
                'pause' => in_array($status, [InboundMoveRun::STATUS_PLANNED, InboundMoveRun::STATUS_RUNNING], true),
                'resume' => InboundMoveRun::STATUS_PAUSED === $status,
                'cancel' => $open,
                // Undo puts back what moved and cancels, in an open run, what did not start yet (InboundMoveRunner::undo()).
                'undo' => $int('undoable') + ($open ? $int('not_started') : 0) > 0,
                'retry' => $int('failed') > 0 && InboundMoveRun::STATUS_CANCELLED !== $status,
            ],
        ];
    }

    /** Which of a run's moves count as "retired" (SQL on service_inbound_move): done. An add removes nothing. */
    public static function retiredSql(string $kind): string
    {
        return InboundMoveRun::KIND_ADD === $kind ? '0' : "state = 'done'";
    }

    /**
     * Which of a run's moves undo can still put back (SQL on service_inbound_move). A done add or remove can, unlike a
     * retired primary move: docs/design/plan-inbound-add-remove.md 7.
     */
    public static function undoableSql(string $kind): string
    {
        return match ($kind) {
            InboundMoveRun::KIND_ADD => "state IN ('switched', 'done', 'failed') AND (skip_reason IS NULL OR skip_reason <> 'already_on_panel')",
            InboundMoveRun::KIND_REMOVE => "state IN ('detaching', 'failed', 'done')",
            default => "state IN ('switched', 'detaching') OR (state = 'failed' AND detach_after IS NOT NULL) OR (state = 'undone' AND next_attempt_at IS NOT NULL)",
        };
    }

    /**
     * Whether the plan no longer agrees with the run: a primary run's target is not the plan's primary, an add's target
     * left the plan, a remove's target is in the plan again (docs/design/plan-inbound-add-remove.md 4).
     *
     * @param list<int> $planAdditional the plan's additional inbound ids (unused for a primary run)
     */
    public static function planChanged(string $kind, ?int $targetId, ?int $planInboundId, array $planAdditional): bool
    {
        return match ($kind) {
            InboundMoveRun::KIND_ADD => !in_array($targetId, $planAdditional, true),
            InboundMoveRun::KIND_REMOVE => $targetId === $planInboundId || in_array($targetId, $planAdditional, true),
            default => $targetId !== $planInboundId,
        };
    }

    /** @return list<int> */
    private function planAdditionalIds(int $planId): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT inbound_id FROM plan_additional_inbound WHERE plan_id = ?', [$planId]));
    }

    /**
     * The service's latest move (the service page's "Inbound move #run" line), or null.
     *
     * @return array<string, mixed>|null
     */
    public function serviceMove(int $serviceId): ?array
    {
        $move = $this->db()->fetchAssociative(
            'SELECT m.*, r.status AS run_status, fi.title AS from_title, ti.title AS to_title
             FROM service_inbound_move m JOIN inbound_move_run r ON r.id = m.run_id
             LEFT JOIN vpn_inbound fi ON fi.id = m.from_inbound_id LEFT JOIN vpn_inbound ti ON ti.id = m.to_inbound_id
             WHERE m.service_id = ? ORDER BY m.id DESC LIMIT 1',
            [$serviceId],
        );
        if (false === $move) {
            return null;
        }
        // An add has no old inbound (from NULL): shown as a dash, not as "#".
        $title = static fn (mixed $title, mixed $remote): string => match (true) {
            null !== $title => mb_substr((string) $title, 0, 60),
            null === $remote => '-',
            default => '#'.mb_substr((string) $remote, 0, 20),
        };

        return [
            'id' => (int) $move['id'],
            'run_id' => (int) $move['run_id'],
            'run_status' => (string) $move['run_status'],
            'from' => $title($move['from_title'], $move['from_remote_inbound_id']),
            'to' => $title($move['to_title'], $move['to_remote_inbound_id']),
            'state' => (string) $move['state'],
            'skip_reason' => null === $move['skip_reason'] ? null : (string) $move['skip_reason'],
            'attempts' => (int) $move['attempts'],
            'next_attempt_at' => null === $move['next_attempt_at'] ? null : (string) $move['next_attempt_at'],
            'given_up' => ServiceInboundMove::STATE_FAILED === $move['state'] && null === $move['next_attempt_at'],
            // Masked again on display (as the additional inbound rows): stored masked, never trusted blindly.
            'last_error' => null === $move['last_error'] ? null : VpnServiceInbound::safeError((string) $move['last_error']),
            'detach_after' => null === $move['detach_after'] ? null : (string) $move['detach_after'],
            'detached_at' => null === $move['detached_at'] ? null : (string) $move['detached_at'],
            'updated_at' => (string) $move['updated_at'],
        ];
    }

    /**
     * Failed and given-up moves (the latest move of services not deleted), on one panel, of one plan, or all: one query.
     *
     * @return array{failed: int, given_up: int}
     */
    public function problems(?int $panelId = null, ?int $planId = null): array
    {
        $where = ["m.state = 'failed'", "s.status <> 'deleted'", self::LATEST_SQL];
        $params = [];
        if (null !== $panelId) {
            $where[] = 's.panel_id = ?';
            $params[] = $panelId;
        }
        if (null !== $planId) {
            $where[] = 'r.plan_id = ?';
            $params[] = $planId;
        }
        $row = $this->db()->fetchAssociative(
            'SELECT COUNT(*) AS failed, COALESCE(SUM(m.next_attempt_at IS NULL), 0) AS given_up
             FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id JOIN inbound_move_run r ON r.id = m.run_id
             WHERE '.implode(' AND ', $where),
            $params,
        ) ?: [];

        return ['failed' => (int) ($row['failed'] ?? 0), 'given_up' => (int) ($row['given_up'] ?? 0)];
    }

    /**
     * The services list's two tabs: services being moved, and services whose move failed (their latest move; deleted
     * services never). One query.
     *
     * @return array{moving: int, failed: int}
     */
    public function listCounts(): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT COALESCE(SUM('.self::MOVING_SQL."), 0) AS moving, COALESCE(SUM(m.state = 'failed'), 0) AS failed
             FROM service_inbound_move m JOIN vpn_service s ON s.id = m.service_id
             WHERE s.status <> 'deleted' AND ".self::LATEST_SQL,
        ) ?: [];

        return ['moving' => (int) ($row['moving'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
