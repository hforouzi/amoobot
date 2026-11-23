<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\VpnPanelDriverInterface;

/** Package lookup after the existing tagged drivers have declined a connection. */
final class VpnProviderResolver
{
    public function __construct(private readonly ProviderDriverRegistry $providers)
    {
    }

    public function forPanel(VpnPanel $panel): ?VpnPanelDriverInterface
    {
        if (!$this->providers->has('vpn', $panel->getType())) {
            return null;
        }
        $driver = $this->providers->get('vpn', $panel->getType());

        return $driver instanceof VpnPanelDriverInterface && $driver->supports($panel) ? $driver : null;
    }
}
