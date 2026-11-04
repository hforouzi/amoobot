<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\InboundDetachResult;

/**
 * Drivers that can take an existing client off some of its inbounds while it stays on the others (3x-ui with the
 * clients API, POST clients/{email}/detach): the "retire" step of moving a service to another inbound
 * (docs/design/plan-inbound-change.md 4.3 step 5, PR P2). A panel whose driver does not implement it (legacy mode,
 * other panel types, the dummy driver) cannot detach: callers skip such a service (NOT_MODERN), nothing is sent.
 *
 * A panel primitive only: nothing calls it yet (the move runner is P4/P6).
 *
 * 3x-ui v3.11.0 has no guard of its own (the design, section 1): it skips an inbound the client is not on, keeps the
 * client's record and traffic, and happily leaves a client on zero inbounds. The guards are ours, in the driver:
 * - never detach the service's primary inbound;
 * - never leave the client on no inbound;
 * - never detach before a read confirms the client's current inbounds, and only inbounds that read lists;
 * - a write counts only when a read afterwards confirms it (a partial or unreadable answer is never a success).
 */
interface InboundDetachDriverInterface
{
    /**
     * Takes the client of $remoteId off those of $inbounds it is on: one GET; when a guard allows it, one detach call with
     * the listed ids only; one GET again. At most three panel calls, each within the open PanelCallBudget pass (if any).
     * Idempotent: inbounds the client is not on are "already detached", and a call where none is listed sends nothing.
     *
     * @param VpnInbound|null  $localPrimary the service's local primary inbound (vpn_service.inbound; null when it was
     *                                       deleted locally): never detached, nor the primary its remote id records
     * @param list<VpnInbound> $inbounds     the inbounds to leave (of $panel)
     * @param bool             $recordHealth false: a failing call is not counted towards the panel's "degraded" state
     *
     * Never throws for a panel or guard outcome: every case is an InboundDetachResult.
     */
    public function detachInbounds(string $remoteId, ?VpnInbound $localPrimary, array $inbounds, ?VpnPanel $panel = null, bool $recordHealth = true): InboundDetachResult;
}
