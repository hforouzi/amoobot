<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

class CreatedVpnService
{
    public function __construct(
        public readonly string $remoteId,
        public readonly string $username,
        public readonly string $subscriptionUrl,
        public readonly string $configText,
    ) {
    }
}
