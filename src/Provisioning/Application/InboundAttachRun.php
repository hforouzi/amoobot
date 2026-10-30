<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What one run of the scheduled retry of additional inbounds did (InboundAttachScheduler::run()). Counts only: no
 * service's credentials, links or panel address.
 */
final class InboundAttachRun
{
    /** The run went through the services that were due (or, with --dry-run, counted them). */
    public const RAN = 'ran';
    /** plans.multi_inbound_enabled is off: nothing was read or changed. */
    public const SWITCHED_OFF = 'switched_off';
    /** plans.attach_retry_enabled is off: nothing was retried or checked. */
    public const DISABLED = 'disabled';

    /** Services looked at (with --dry-run: services that are due). */
    public int $services = 0;
    /** Rows attached by a retry. */
    public int $attached = 0;
    /** Rows a retry tried and that are still failed. */
    public int $stillFailed = 0;
    /** Reconcile: rows recorded as attached whose client is no longer on that inbound (now failed, retried). */
    public int $foundRemoved = 0;
    /** Reconcile: failed or stale pending rows the panel already has (now attached, no attempt counted). */
    public int $foundAttached = 0;
    /** Services whose client is not on the panel at all (rows left as they are; usage sync reports the client). */
    public int $clientMissing = 0;
    /** Services the panel could not be asked about (unreachable, refused). */
    public int $panelFailures = 0;
    /** Services skipped: no longer active, panel not modern, or their panel stopped for this run. */
    public int $skipped = 0;
    /** Admin alerts sent (one per service and kind: failed, given up, attached again). */
    public int $alertsSent = 0;
    /**
     * Panels this run stopped asking after MAX_CONSECUTIVE_FAILURES failures in a row: panel id => the service id it
     * stopped at.
     *
     * @var array<int, int>
     */
    public array $stoppedPanels = [];

    public function __construct(public readonly string $outcome = self::RAN)
    {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'services' => $this->services,
            'attached' => $this->attached,
            'still_failed' => $this->stillFailed,
            'found_removed' => $this->foundRemoved,
            'found_attached' => $this->foundAttached,
            'client_missing' => $this->clientMissing,
            'panel_failures' => $this->panelFailures,
            'skipped' => $this->skipped,
            'alerts_sent' => $this->alertsSent,
            'stopped_panels' => count($this->stoppedPanels),
        ];
    }
}
