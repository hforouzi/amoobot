<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Module\Domain\ProviderDriverInterface;

/**
 * The VPN module's driver contract (docs/design/product-modules.md 6.3): the provider-driver base plus today's
 * VpnPanelDriverInterface, which stays as it is so every existing caller and the 'app.vpn_driver' tag are untouched.
 *
 * PM1a: the required operations are VpnPanelDriverInterface's (createService, renewService, suspendService,
 * deleteService, resetUsage, getUsage) with today's names and the VpnPanel as the connection. The design's renamed
 * methods (createUser(..., Connection), ...) and listInbounds() arrive with the Connection type (PM5b) or the first
 * driver that needs them. Optional capabilities are the existing optional interfaces (VpnCapability maps them).
 */
interface VpnDriverInterface extends ProviderDriverInterface, VpnPanelDriverInterface
{
    public const MODULE = 'vpn';
    public const VERSION = 1;
}
