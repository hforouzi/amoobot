<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection;

final readonly class ModernConnectionTestResult
{
    public function __construct(
        public bool $success,
        public ModernConnectionTestStatus $status,
        public string $message,
        public bool $panelReachable,
        public bool $authenticated,
        public bool $compatibleApi,
        public ?string $requestId = null,
        public ?int $responseTimeMs = null,
        public ?string $panelVersion = null,
        public ?string $xrayVersion = null,
        public ?string $xrayState = null,
    ) {
    }

    public static function failure(
        ModernConnectionTestStatus $status,
        string $message,
        bool $panelReachable = false,
        bool $authenticated = false,
        bool $compatibleApi = false,
        ?string $requestId = null,
        ?int $responseTimeMs = null,
    ): self {
        return new self(
            success: false,
            status: $status,
            message: self::sanitize($message),
            panelReachable: $panelReachable,
            authenticated: $authenticated,
            compatibleApi: $compatibleApi,
            requestId: $requestId,
            responseTimeMs: $responseTimeMs
        );
    }

    public static function success(
        string $message,
        string $requestId,
        int $responseTimeMs,
        ?string $panelVersion = null,
        ?string $xrayVersion = null,
        ?string $xrayState = null,
    ): self {
        return new self(
            success: true,
            status: ModernConnectionTestStatus::SUCCESS,
            message: self::sanitize($message),
            panelReachable: true,
            authenticated: true,
            compatibleApi: true,
            requestId: $requestId,
            responseTimeMs: $responseTimeMs,
            panelVersion: $panelVersion,
            xrayVersion: $xrayVersion,
            xrayState: $xrayState
        );
    }

    private static function sanitize(string $message): string
    {
        $safe = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [redacted]', $message) ?? $message;
        $safe = preg_replace('/([?&](?:api[_-]?token|access[_-]?token|token|password|passwd|cookie|session|authorization)=)[^&#\s]*/i', '$1[redacted]', $safe) ?? $safe;
        $safe = preg_replace('/("?(?:password|passwd|token|cookie|session|authorization|secret|api[_-]?key)"?\s*[:=]\s*)"[^"]*"/i', '$1"[redacted]"', $safe) ?? $safe;
        $safe = preg_replace('/\b(token|secret|password|passwd)\s+((?=[A-Za-z0-9._~+\/=-]*[._~+\/=-])[A-Za-z0-9._~+\/=-]{6,})/i', '$1 [redacted]', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr(trim($safe), 0, 300);
    }
}
