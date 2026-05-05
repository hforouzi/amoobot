<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\VpnPanelDriverInterface;

class VpnPanelDriverRegistry
{
    /**
     * @param iterable<VpnPanelDriverInterface> $drivers
     */
    public function __construct(private readonly iterable $drivers)
    {
    }

    public function resolve(?VpnPanel $panel): VpnPanelDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($panel)) {
                return $driver;
            }
        }

        throw new \RuntimeException('No VPN driver supports requested panel');
    }
}
