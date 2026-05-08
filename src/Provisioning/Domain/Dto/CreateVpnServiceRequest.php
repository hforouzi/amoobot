<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

use App\Entity\VpnInbound;

class CreateVpnServiceRequest
{
    public function __construct(
        public readonly string $username,
        public readonly int $durationDays,
        public readonly ?int $trafficLimitGb = null,
        public readonly ?int $ipLimit = null,
        public readonly ?VpnInbound $inbound = null,
        public readonly ?string $remoteInboundId = null,
        public readonly array $meta = [],
    ) {
    }
}
