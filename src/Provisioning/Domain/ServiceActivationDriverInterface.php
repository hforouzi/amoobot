<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;

/**
 * Drivers that can re-enable a suspended client without touching its expiry or traffic limit.
 * (On 3x-ui this only flips the enable flag: a client that is out of traffic or expired is disabled again by the
 * panel's own traffic loop.)
 */
interface ServiceActivationDriverInterface
{
    public function activateService(string $remoteId, ?VpnPanel $panel = null): void;
}
