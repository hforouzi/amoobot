<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Plan;
use App\Entity\VpnService;
use App\Provisioning\Application\RenewalSettingsProvider;

final class PlanPricingService
{
    public function __construct(
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
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
        $discountAmount = $this->calculateDiscountAmount($baseAmount, $discountPercent);
        $finalAmount = max(0, $baseAmount - $discountAmount);

        return new PriceCalculationResult(
            baseAmount: $baseAmount,
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            finalAmount: $finalAmount,
            source: 'current_plan',
            explanation: $plan->isCustomizable() ? 'custom_plan_current_price' : 'fixed_plan_current_price',
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
            discountAmount: $price->discountAmount,
            finalAmount: $price->finalAmount,
            planPriceSource: 'current_plan',
            explanation: 'renewal_uses_current_plan_price'
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
