<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class VpnInboundSyncResult
{
    /**
     * @param array<int, string> $warnings
     */
    public function __construct(
        public readonly int $syncedCount,
        public readonly int $missingLocalCount = 0,
        public readonly array $warnings = [],
    ) {
    }
}
