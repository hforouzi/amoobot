<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure;

use App\Entity\VpnPanel;
use App\Module\Application\VpnProviderResolver;
use App\Provisioning\Domain\VpnPanelDriverInterface;

class VpnPanelDriverRegistry
{
    /**
     * @param iterable<VpnPanelDriverInterface> $drivers
     */
    public function __construct(private readonly iterable $drivers, private readonly ?VpnProviderResolver $providers = null)
    {
    }

    public function resolve(?VpnPanel $panel): VpnPanelDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($panel)) {
                return $driver;
            }
        }

        if (null !== $panel && null !== ($driver = $this->providers?->forPanel($panel))) {
            return $driver;
        }

        throw new \RuntimeException('No VPN driver supports requested panel');
    }
}
