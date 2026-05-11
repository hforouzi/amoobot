<?php

declare(strict_types=1);

namespace App\Shop\Application;

final class RenewalPriceResult
{
    public function __construct(
        public readonly int $trafficGb,
        public readonly int $durationDays,
        public readonly bool $unlimitedDuration,
        public readonly int $baseAmount,
        public readonly int $discountPercent,
        public readonly int $finalAmount,
        public readonly string $planPriceSource = 'current_plan',
    ) {
    }
}
