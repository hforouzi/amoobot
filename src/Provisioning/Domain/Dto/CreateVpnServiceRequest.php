<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

class CreateVpnServiceRequest
{
    public function __construct(
        public readonly string $username,
        public readonly int $durationDays,
        public readonly ?int $trafficLimitGb = null,
        public readonly array $meta = [],
    ) {
    }
}
