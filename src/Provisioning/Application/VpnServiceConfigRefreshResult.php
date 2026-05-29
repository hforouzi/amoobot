<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final readonly class VpnServiceConfigRefreshResult
{
    /**
     * @param list<string> $configLinks
     */
    private function __construct(
        public bool $attempted,
        public bool $succeeded,
        public array $configLinks,
        public ?string $subscriptionUrl,
        public ?string $failureReason,
    ) {
    }

    /**
     * @param list<string> $configLinks
     */
    public static function success(array $configLinks, ?string $subscriptionUrl = null): self
    {
        return new self(true, true, $configLinks, $subscriptionUrl, null);
    }

    public static function failed(string $reason): self
    {
        return new self(true, false, [], null, $reason);
    }

    public static function skipped(): self
    {
        return new self(false, false, [], null, null);
    }
}
