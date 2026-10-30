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
        /**
         * The plan's additional inbounds (docs/design/multi-inbound-plans.md 3.2): the client is attached to them too.
         * Only drivers implementing InboundAttachDriverInterface accept any.
         *
         * @var list<VpnInbound>
         */
        public readonly array $additionalInbounds = [],
    ) {
    }
}
