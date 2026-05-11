<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;

final class ServiceTrafficAddonResult
{
    public function __construct(
        public readonly VpnService $service,
        public readonly int $addedTrafficGb,
        public readonly int $newTrafficLimitGb,
    ) {
    }
}
