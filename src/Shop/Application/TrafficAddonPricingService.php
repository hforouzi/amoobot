<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;

final class TrafficAddonPricingService
{
    public function __construct(
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
    ) {
    }

    public function calculate(int $trafficGb): PriceCalculationResult
    {
        $safeTrafficGb = max(0, $trafficGb);
        $pricePerGb = $this->trafficAddonSettingsProvider->pricePerGb();
        $baseAmount = $safeTrafficGb * $pricePerGb;
        $globalDiscountPercent = $this->resolveGlobalDiscountPercent();
        $globalDiscountAmount = $this->calculateDiscountAmount($baseAmount, $globalDiscountPercent);
        $afterGlobalDiscountAmount = max(0, $baseAmount - $globalDiscountAmount);
        $finalAmount = $afterGlobalDiscountAmount;

        return new PriceCalculationResult(
            baseAmount: $baseAmount,
            globalDiscountPercent: $globalDiscountPercent,
            globalDiscountAmount: $globalDiscountAmount,
            afterGlobalDiscountAmount: $afterGlobalDiscountAmount,
            finalAmount: $finalAmount,
            source: 'traffic_addon',
            explanation: 'traffic_addon_current_price',
        );
    }

    private function resolveGlobalDiscountPercent(): int
    {
        $percent = (int) floor($this->renewalSettingsProvider->globalDiscountPercent());

        return max(0, min(100, $percent));
    }

    private function calculateDiscountAmount(int $baseAmount, int $discountPercent): int
    {
        $baseAmount = max(0, $baseAmount);
        if ($discountPercent <= 0) {
            return 0;
        }

        return (int) floor(($baseAmount * $discountPercent) / 100);
    }
}
