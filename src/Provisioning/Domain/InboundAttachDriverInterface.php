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

    /**
     * Attaches the existing client of $remoteId to those of $inbounds it is not on yet: one GET, one attach call with
     * the missing ids only (one by one when the panel refuses the batch), one GET again. Never creates a client.
     *
     * @param list<VpnInbound> $inbounds
     *
     * @return list<InboundAttachment> one per inbound, in the given order
     *
     * @throws \RuntimeException the client could not be read (missing on the panel, panel unreachable)
     */
    public function attachInbounds(string $remoteId, array $inbounds, ?VpnPanel $panel = null): array;
}
