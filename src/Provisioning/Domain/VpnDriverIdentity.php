<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * The parts of VpnDriverInterface that are the same for every VPN driver: the module and the contract version. Each
 * driver still states its own code() and capabilities().
 */
trait VpnDriverIdentity
{
    public function module(): string
    {
        return VpnDriverInterface::MODULE;
    }

    public function contractVersion(): int
    {
        return VpnDriverInterface::VERSION;
    }
}
