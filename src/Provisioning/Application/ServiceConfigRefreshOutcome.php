<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final readonly class ServiceConfigRefreshOutcome
{
    private function __construct(
        public bool $attempted,
        public bool $succeeded,
        public bool $skipped,
        public bool $fallbackToStored,
        public int $refreshedLinkCount,
        public ?string $reason,
    ) {
    }

    public static function success(int $refreshedLinkCount): self
    {
        return new self(true, true, false, false, $refreshedLinkCount, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(true, false, true, false, 0, $reason);
    }

    public static function failed(string $reason, bool $fallbackToStored): self
    {
        return new self(true, false, false, $fallbackToStored, 0, $reason);
    }
}
