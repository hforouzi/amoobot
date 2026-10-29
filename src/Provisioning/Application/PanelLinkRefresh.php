<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What PanelLinkSnapshot::refresh() found (and, unless it was a dry run, stored): the service's links before and as
 * the panel renders them now. $error is for admins (names the failure, never a token or subscription id); customers
 * are only ever told that the links could not be refreshed.
 */
final readonly class PanelLinkRefresh
{
    /** New links stored (or, in a dry run, would be). */
    public const UPDATED = 'updated';
    /** The panel gives exactly the stored links. */
    public const UNCHANGED = 'unchanged';
    /** The panel answered with no links while links are stored: the stored ones are kept. */
    public const KEPT_EMPTY_ANSWER = 'kept_empty_answer';
    /** No links stored and none on the panel. */
    public const EMPTY = 'empty';
    /** The panel could not be asked or refused: the stored links are kept. */
    public const FAILED = 'failed';
    /** The service has no client on the panel to ask about (no panel, or a remote id the driver cannot read). */
    public const NO_IDENTITY = 'no_identity';
    /** The service's credentials changed between the panel read and the write, twice: nothing was written. */
    public const CHANGED_MEANWHILE = 'changed_meanwhile';

    /**
     * @param list<string> $before
     * @param list<string> $after            what the panel gives now (empty after a failure)
     * @param bool         $changedMeanwhile the credentials changed after a first read: the links were read again
     */
    public function __construct(
        public string $outcome,
        public array $before,
        public array $after,
        public int $rejected = 0,
        public ?string $error = null,
        public bool $applied = false,
        public bool $changedMeanwhile = false,
    ) {
    }

    public function withChangedMeanwhile(): self
    {
        return new self($this->outcome, $this->before, $this->after, $this->rejected, $this->error, $this->applied, true);
    }

    public function succeeded(): bool
    {
        return self::UPDATED === $this->outcome || self::UNCHANGED === $this->outcome;
    }

    /** The links the snapshot holds after this refresh (the old ones when nothing was stored). */
    public function snapshotCount(): int
    {
        return $this->succeeded() ? count($this->after) : count($this->before);
    }

    public function shrank(): bool
    {
        return self::UPDATED === $this->outcome && count($this->after) < count($this->before);
    }

    /** @return list<string> */
    public function added(): array
    {
        return $this->succeeded() ? array_values(array_diff($this->after, $this->before)) : [];
    }

    /** @return list<string> */
    public function removed(): array
    {
        return $this->succeeded() ? array_values(array_diff($this->before, $this->after)) : [];
    }
}
