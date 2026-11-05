<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/** What one pass of the scheduled task did (InboundMoveScheduler). Counts only: no service, owner or link. */
final class InboundMoveTaskRun
{
    public const DONE = 'done';
    public const SWITCHED_OFF = 'switched_off';
    public const LOCKED = 'locked';

    public int $runs = 0;
    public int $moved = 0;
    public int $retired = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public int $alertsSent = 0;
    /** Runs paused by this pass (5 failed panel reads in a row). */
    public int $paused = 0;
    /** Runs that threw: the others went on. */
    public int $errors = 0;
    /** The time budget ended the pass: the next pass picks up the rest. */
    public bool $outOfTime = false;
    /** --dry-run: moves that are due, nothing was done. */
    public int $due = 0;

    public function __construct(public readonly string $outcome = self::DONE)
    {
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'runs' => $this->runs,
            'moved' => $this->moved,
            'retired' => $this->retired,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'alerts_sent' => $this->alertsSent,
            'paused' => $this->paused,
            'errors' => $this->errors,
            'out_of_time' => $this->outOfTime,
            'due' => $this->due,
        ];
    }
}
