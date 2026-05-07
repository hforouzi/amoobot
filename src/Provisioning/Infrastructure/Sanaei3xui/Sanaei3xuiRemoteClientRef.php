<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

final class Sanaei3xuiRemoteClientRef
{
    public function __construct(
        public readonly int $inboundId,
        public readonly string $clientId,
        public readonly string $email,
    ) {
    }
}
