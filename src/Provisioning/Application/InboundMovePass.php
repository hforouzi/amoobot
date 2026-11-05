<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What one pass of InboundMoveRunner::process() did to a run (for the console and the tests). Counts and fixed texts
 * only: no credential, link, owner or panel address.
 */
final class InboundMovePass
{
    /** The pass ran; see the run's status. */
    public const RAN = 'ran';
    /** plans.inbound_move_enabled is off: nothing was done. */
    public const SWITCHED_OFF = 'switched_off';
    /** The run does not exist, is not open (done, cancelled), or is not a primary move. */
    public const NOT_OPEN = 'not_open';
    /** Called inside a transaction: nothing was done (panel calls never run under someone's locks). */
    public const REFUSED = 'refused';

    public int $switched = 0;
    public int $alreadyOnTarget = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public int $snapshotsRefreshed = 0;
    public int $snapshotsPending = 0;
    public int $alertsSent = 0;
    /** Phase 2 (P6): old inbounds removed in this pass, and new inbounds attached again first. */
    public int $retired = 0;
    public int $reattached = 0;
    /** Moved services whose old inbound is due to be removed later (after the grace period), and the earliest time. */
    public int $retirePending = 0;
    public ?string $retireNextAt = null;
    /** Moved services that keep the old inbound (--keep-old, or A is one of their additional inbounds). */
    public int $keptOld = 0;
    /** --undo: services put back on the old inbound; already retired (cannot be undone); in another process's hands. */
    public int $undone = 0;
    public int $alreadyRetired = 0;
    public int $busy = 0;
    /** --undo / --cancel: services not moved yet that will not be moved any more. */
    public int $cancelled = 0;
    public string $runStatus = '';
    public ?string $pauseReason = null;
    /** @var array{planned: int, done: int, failed: int, skipped: int, remaining: int} */
    public array $counts = ['planned' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0];

    public function __construct(public readonly string $outcome = self::RAN, public readonly ?string $message = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'message' => $this->message,
            'run_status' => $this->runStatus,
            'pause_reason' => $this->pauseReason,
            'switched' => $this->switched,
            'already_on_target' => $this->alreadyOnTarget,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'snapshots_refreshed' => $this->snapshotsRefreshed,
            'snapshots_pending' => $this->snapshotsPending,
            'alerts_sent' => $this->alertsSent,
            'retired' => $this->retired,
            'reattached' => $this->reattached,
            'retire_pending' => $this->retirePending,
            'retire_next_at' => $this->retireNextAt,
            'kept_old' => $this->keptOld,
            'undone' => $this->undone,
            'already_retired' => $this->alreadyRetired,
            'busy' => $this->busy,
            'cancelled' => $this->cancelled,
            'counts' => $this->counts,
        ];
    }
}
