<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Sync;

final readonly class ModernInboundSyncResult
{
    public function __construct(
        public bool $success,
        public ModernInboundSyncStatus $status,
        public string $message,
        public int $fetchedCount = 0,
        public int $createdCount = 0,
        public int $updatedCount = 0,
        public int $unchangedCount = 0,
        public int $missingCount = 0,
        public int $deactivatedCount = 0,
        public ?string $requestId = null,
        public ?int $durationMs = null,
    ) {
    }

    public static function failure(
        ModernInboundSyncStatus $status,
        string $message,
        ?string $requestId = null,
        ?int $durationMs = null,
    ): self {
        return new self(
            success: false,
            status: $status,
            message: self::redact($message),
            requestId: $requestId,
            durationMs: $durationMs
        );
    }

    private static function redact(string $message): string
    {
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?token|token|password)=([^\s&]+)/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(api\s+token|token|password)\s+[^\s]+/i', '$1 [redacted]', $message) ?? $message;

        return $message;
    }
}
