<?php

declare(strict_types=1);

namespace App\Payment\Application\Repair;

final readonly class PanelStateComparison
{
    public const UNCHANGED = 'unchanged';
    public const AHEAD = 'ahead';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $state,
        public string $detail,
    ) {
    }
}
