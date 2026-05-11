<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class ExpirySummary
{
    public function __construct(
        public readonly int $checked,
        public readonly int $updated,
        public readonly int $failed,
        public readonly int $skipped,
    ) {
    }
}
