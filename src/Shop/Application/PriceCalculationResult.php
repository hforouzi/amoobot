<?php

declare(strict_types=1);

namespace App\Shop\Application;

final class PriceCalculationResult
{
    public function __construct(
        public readonly int $baseAmount,
        public readonly int $discountPercent,
        public readonly int $finalAmount,
    ) {
    }
}
