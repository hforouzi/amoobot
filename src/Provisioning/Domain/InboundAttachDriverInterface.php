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
    /** An additional inbound not tried within a time-limited pass (time or one-by-one limit used up): pending. */
    public const NOT_TRIED = 'not tried (time limit of the pass); the scheduled run attaches it';
    /**
     * An additional inbound the client was not on after the sale's add (M2d B1): pending, not tried. Attached right after
     * the commit (ServiceInboundAttacher::afterSale(), no lock held), else by the scheduled run.
     */
    public const AFTER_SALE = 'being attached after the payment (then by the scheduled run)';

    /**
     * Attaches the existing client of $remoteId to those of $inbounds it is not on yet: one GET, one attach call with
     * the missing ids only (one by one when the panel refuses the batch), one GET again. Never creates a client.
     *
     * @param list<VpnInbound> $inbounds
     * @param bool             $recordHealth false: a failing call is not counted towards the panel's "degraded" state
     *                                       (the scheduled retry, a bulk run)
     * @param float|null       $deadline     the end of a time-limited pass (PanelCallBudget seconds): no new call after it,
     *                                       at most a few one-by-one attaches; what is left is pending (NOT_TRIED)
     *
     * @return list<InboundAttachment> one per inbound, in the given order
     *
     * @throws \RuntimeException the client could not be read (missing on the panel, panel unreachable)
     */
    public function attachInbounds(string $remoteId, array $inbounds, ?VpnPanel $panel = null, bool $recordHealth = true, ?float $deadline = null): array;

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
