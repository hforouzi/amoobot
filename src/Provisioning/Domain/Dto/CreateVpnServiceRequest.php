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
        /** Client UUID to use (derived from the order, so a retry can find the client again); null = driver picks. */
        public readonly ?string $clientUuid = null,
    ) {
    }
}
