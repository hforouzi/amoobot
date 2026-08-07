<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Api;

final readonly class ModernInboundApiResponse
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        public array $payload,
        public string $requestId,
    ) {
    }
}
