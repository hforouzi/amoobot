<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Plan;
use App\Entity\VpnService;
use App\Shared\Infrastructure\SettingValueProvider;

final class PlanPricingService
{
    public function __construct(
        private readonly SettingValueProvider $settingValueProvider,
        private readonly string $pricingGlobalDiscountPercent = '0',
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function calculateNewOrderAmount(Plan $plan, array $options = []): int
    {
        return $this->calculateNewOrderPrice($plan, $options)->finalAmount;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function calculateNewOrderPrice(Plan $plan, array $options = []): PriceCalculationResult
    {
        $trafficGb = isset($options['trafficGb']) ? (int) $options['trafficGb'] : null;
        $durationDays = isset($options['durationDays']) ? (int) $options['durationDays'] : null;
        $baseAmount = $this->calculatePlanBaseAmount($plan, $trafficGb, $durationDays);
        $discountPercent = $this->resolveGlobalDiscountPercent();
        $finalAmount = $this->applyDiscount($baseAmount, $discountPercent);

        return new PriceCalculationResult(
            baseAmount: $baseAmount,
            discountPercent: $discountPercent,
            finalAmount: $finalAmount
        );
    }

    public function calculateRenewalAmount(VpnService $service, Plan $plan): ?RenewalPriceResult
    {
        $sourceOrder = $service->getOrder();
        if (null === $sourceOrder) {
            return null;
        }

        $metadata = is_array($sourceOrder->getMetadata()) ? $sourceOrder->getMetadata() : [];
        $trafficGb = (int) ($metadata['trafficGb'] ?? 0);
        if ($trafficGb <= 0) {
            $trafficGb = (int) ($plan->getTrafficGb() ?? 0);
        }
        if ($trafficGb <= 0) {
            return null;
        }

        $unlimitedDuration = true === ($metadata['unlimitedDuration'] ?? false) || $plan->isUnlimitedDuration();
        $durationDays = 0;
        if (!$unlimitedDuration) {
            $durationDays = (int) ($metadata['durationDays'] ?? 0);
            if ($durationDays <= 0) {
                $durationDays = (int) $plan->getDurationDays();
            }
            if ($durationDays <= 0) {
                return null;
            }
        }

        $price = $this->calculateNewOrderPrice($plan, [
            'trafficGb' => $trafficGb,
            'durationDays' => $durationDays,
        ]);

        return new RenewalPriceResult(
            trafficGb: $trafficGb,
            durationDays: $durationDays,
            unlimitedDuration: $unlimitedDuration,
            baseAmount: $price->baseAmount,
            discountPercent: $price->discountPercent,
            finalAmount: $price->finalAmount,
            planPriceSource: 'current_plan'
        );
    }

    private function calculatePlanBaseAmount(Plan $plan, ?int $trafficGb, ?int $durationDays): int
    {
        if (!$plan->isCustomizable()) {
            return max(0, $plan->getPrice());
        }

        $amount = 0;
        $pricePerGb = max(0, (int) ($plan->getPricePerGb() ?? 0));
        $pricePerDay = max(0, (int) ($plan->getPricePerDay() ?? 0));
        if ($pricePerGb > 0 && null !== $trafficGb && $trafficGb > 0) {
            $amount += $trafficGb * $pricePerGb;
        }
        if (!$plan->isUnlimitedDuration() && $pricePerDay > 0 && null !== $durationDays && $durationDays > 0) {
            $amount += $durationDays * $pricePerDay;
        }

        if ($amount <= 0) {
            $amount = max(0, $plan->getPrice());
        }

        return $amount;
    }

    private function resolveGlobalDiscountPercent(): int
    {
        $value = $this->settingValueProvider->get('pricing.global_discount_percent', $this->pricingGlobalDiscountPercent);
        $percent = is_string($value) ? (int) floor((float) trim($value)) : 0;

        return max(0, min(100, $percent));
    }

    private function applyDiscount(int $baseAmount, int $discountPercent): int
    {
        $baseAmount = max(0, $baseAmount);
        if ($discountPercent <= 0) {
            return $baseAmount;
        }

        $discountValue = (int) floor(($baseAmount * $discountPercent) / 100);

        return max(0, $baseAmount - $discountValue);
    }
}
