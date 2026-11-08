<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What one app:panel:import-clients run did (or, in a dry run, would do): counts only, never a client's identity.
 * Every client read lands in exactly one of imported, alreadyKnown, noOwner, unknownInbound, invalid. A run stopped by
 * $stopped (STOPPED_*) wrote nothing for the batch it was reading; the batches before it are committed.
 */
final class PanelClientImportSummary
{
    /** The panel is not in modern mode: the legacy API cannot list clients with their owner. Nothing was read. */
    public const STOPPED_NOT_MODERN = 'not_modern';
    /** The panel could not be read ($failure says how). */
    public const STOPPED_PANEL_READ = 'panel_read';

    public int $read = 0;
    public int $imported = 0;
    public int $newUsers = 0;
    public int $existingUsers = 0;
    public int $alreadyKnown = 0;
    public int $noOwner = 0;
    public int $unknownInbound = 0;
    public int $invalid = 0;
    /** Whether any client row the panel returned had a tgId field at all (false: the panel does not report owners). */
    public bool $tgIdReported = false;
    /** Whether --limit stopped the run before every client was read. */
    public bool $limitReached = false;
    public ?string $stopped = null;
    /** A PanelFailure value when $stopped is STOPPED_PANEL_READ. */
    public ?string $failure = null;

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function completed(): bool
    {
        return null === $this->stopped;
    }
}
