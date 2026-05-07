<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

class VpnUsage
{
    public function __construct(
        public readonly ?int $trafficUsedGb = null,
        public readonly ?int $trafficLimitGb = null,
    ) {
    }
}
