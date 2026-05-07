<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

final class Sanaei3xuiRemoteClientRef
{
    public function __construct(
        public readonly string $inboundId,
        public readonly string $clientId,
        public readonly string $email,
        public readonly ?int $panelId = null,
        public readonly ?int $localInboundId = null,
        public readonly ?string $subId = null,
    ) {
    }
}
