<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

class RenewVpnServiceRequest
{
    public function __construct(
        public readonly int $durationDays,
        public readonly ?int $trafficLimitGb = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly bool $unlimitedDuration = false,
    ) {
    }
}
