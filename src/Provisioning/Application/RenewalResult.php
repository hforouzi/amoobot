<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;

final class RenewalResult
{
    public function __construct(
        public readonly VpnService $service,
        public readonly ?\DateTimeImmutable $newExpiresAt,
        public readonly ?int $newTrafficLimitGb,
        public readonly int $addedTrafficGb,
        public readonly bool $unlimitedDuration,
    ) {
    }
}
