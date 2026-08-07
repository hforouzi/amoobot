<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Dto;

final readonly class ModernInboundDto
{
    /**
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $streamSettings
     * @param array<string, mixed>|null $sniffing
     * @param array<mixed>              $clientStats
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        public int $remoteId,
        public string $remark,
        public string $protocol,
        public int $port,
        public bool $enabled,
        public int $expiryTime,
        public string $listen,
        public string $tag,
        public int $total,
        public int $up,
        public int $down,
        public ?array $settings,
        public ?array $streamSettings,
        public ?array $sniffing,
        public array $clientStats,
        public array $raw,
    ) {
    }
}
