<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final readonly class VpnConfigLinkSetResult
{
    /**
     * @param list<string> $finalLinks
     */
    public function __construct(
        public int $rawCount,
        public int $formattedCount,
        public array $finalLinks,
        public int $droppedDuplicateCount,
    ) {
    }
}
