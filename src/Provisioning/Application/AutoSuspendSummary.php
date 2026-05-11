<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class AutoSuspendSummary
{
    public function __construct(
        public readonly int $checked,
        public readonly int $suspended,
        public readonly int $failed,
        public readonly int $skipped,
    ) {
    }
}

