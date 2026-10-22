<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Plan;

/**
 * What renewing a service costs and gives, from its plan as it is now and the renewal settings.
 */
final class RenewalQuote
{
    public function __construct(
        public readonly Plan $plan,
        public readonly int $amount,
        public readonly int $trafficGb,
        public readonly int $durationDays,
        public readonly bool $unlimitedDuration,
        public readonly int $baseAmount,
        public readonly int $globalDiscountPercent,
        public readonly int $globalDiscountAmount,
        public readonly int $afterGlobalDiscountAmount,
        public readonly bool $carryRemainingTraffic,
        public readonly bool $carryRemainingDays,
    ) {
    }
}
