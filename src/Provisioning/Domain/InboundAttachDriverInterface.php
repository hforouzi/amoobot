<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\InboundAttachment;

/**
 * Drivers that can put one client on several inbounds of its panel (3x-ui with the clients API): plans with additional
 * inbounds (docs/design/multi-inbound-plans.md 3) are provisioned only through such a driver. A panel whose driver does
 * not implement it (legacy mode, other panel types) fails such a provisioning closed, with MODERN_PANEL_REQUIRED.
 */
interface InboundAttachDriverInterface
{
    public const MODERN_PANEL_REQUIRED = 'Several inbounds need the panel in modern mode (Panels → test connection).';
    /** An additional inbound whose attach was sent but could not be read back (M2c F2): pending. */
    public const NOT_CONFIRMED = 'not confirmed: the panel could not be read after the attach; the scheduled run checks it';
    /** An additional inbound not tried at the sale (M2c F5: time budget or fallback limit used up): pending. */
    public const NOT_TRIED = 'not tried at the sale (time limit); the scheduled run attaches it';

    /**
     * Attaches the existing client of $remoteId to those of $inbounds it is not on yet: one GET, one attach call with
     * the missing ids only (one by one when the panel refuses the batch), one GET again. Never creates a client.
     *
     * @param list<VpnInbound> $inbounds
     * @param bool             $recordHealth false: a failing call is not counted towards the panel's "degraded" state
     *                                       (the scheduled retry, a bulk run)
     *
     * @return list<InboundAttachment> one per inbound, in the given order
     *
     * @throws \RuntimeException the client could not be read (missing on the panel, panel unreachable)
     */
    public function attachInbounds(string $remoteId, array $inbounds, ?VpnPanel $panel = null, bool $recordHealth = true): array;

    /**
     * The panel ids of the inbounds the client of $remoteId is on (one GET, read only): the scheduled reconcile of the
     * vpn_service_inbound rows with the panel (M2b).
     *
     * @return list<int>|null null: there is no such client on the panel
     *
     * @throws \RuntimeException the panel could not be read, or did not list the client's inbounds
     */
    public function clientInboundIds(string $remoteId, ?VpnPanel $panel = null, bool $recordHealth = true): ?array;
}
