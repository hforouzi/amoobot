<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;

/**
 * Drivers that can set a client's traffic limit alone (traffic add-on), leaving expiry and status as they are.
 */
interface TrafficLimitDriverInterface
{
    /**
     * @param int $trafficLimitGb the new absolute limit (not a delta), so a retry sets the same value again
     */
    public function addTrafficLimit(string $remoteId, int $trafficLimitGb, ?VpnPanel $panel = null): void;
}
