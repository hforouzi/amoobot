<?php

declare(strict_types=1);

namespace App\Shop\Application;

final class PriceCalculationResult
{
    public function __construct(
        public readonly int $baseAmount,
        public readonly int $globalDiscountPercent,
        public readonly int $globalDiscountAmount,
        public readonly int $afterGlobalDiscountAmount,
        public readonly int $finalAmount,
        public readonly string $source = 'current_plan',
        public readonly string $explanation = 'current_plan_pricing',
    ) {
    }
}
