<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Plan;

/**
 * What a customer chooses for a new service: the account name, and traffic / duration where the plan lets them
 * (customizable, priced per GB / per day, with a range). The rules the bot's purchase dialogue always used.
 */
final class PlanOptions
{
    public function asksTraffic(Plan $plan): bool
    {
        return $this->hasCustomTrafficPricing($plan)
            && $plan->getMinTrafficGb() !== $plan->getMaxTrafficGb();
    }

    public function asksDuration(Plan $plan): bool
    {
        return !$plan->isUnlimitedDuration()
            && $this->hasCustomDurationPricing($plan)
            && $plan->getMinDurationDays() !== $plan->getMaxDurationDays();
    }

    public function defaultTrafficGb(Plan $plan): ?int
    {
        if ($this->hasCustomTrafficPricing($plan) && $plan->getMinTrafficGb() === $plan->getMaxTrafficGb()) {
            return $plan->getMinTrafficGb();
        }

        return $plan->getTrafficGb();
    }

    public function defaultDurationDays(Plan $plan): ?int
    {
        if ($plan->isUnlimitedDuration()) {
            return null;
        }

        if (null !== $plan->getMinDurationDays() && $plan->getMinDurationDays() === $plan->getMaxDurationDays()) {
            return $plan->getMinDurationDays();
        }

        return $plan->getDurationDays();
    }

    public function isValidTraffic(Plan $plan, ?int $trafficGb): bool
    {
        $min = $plan->getMinTrafficGb();
        $max = $plan->getMaxTrafficGb();

        return null !== $trafficGb && null !== $min && null !== $max && $trafficGb >= $min && $trafficGb <= $max;
    }

    public function isValidDuration(Plan $plan, ?int $durationDays): bool
    {
        $min = $plan->getMinDurationDays();
        $max = $plan->getMaxDurationDays();

        return null !== $durationDays && null !== $min && null !== $max && $durationDays >= $min && $durationDays <= $max;
    }

    /** a-z, 0-9 and _ only, 3 to 24 characters (anything else is dropped first); null when nothing valid is left. */
    public function normalizeAccountName(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
        if (!preg_match('/^[a-z0-9_]{3,24}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /** The panel username: the chosen name plus a random suffix, so two customers can pick the same name. */
    public function finalUsername(string $prefix): string
    {
        return sprintf('%s_%d', $prefix, random_int(10000, 99999));
    }

    /** A positive whole number typed by the customer, else null. */
    public static function parsePositiveInt(string $text): ?int
    {
        $value = trim($text);
        if (!preg_match('/^\d+$/', $value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function hasCustomTrafficPricing(Plan $plan): bool
    {
        return $plan->isCustomizable()
            && null !== $plan->getMinTrafficGb()
            && null !== $plan->getMaxTrafficGb()
            && (int) ($plan->getPricePerGb() ?? 0) > 0;
    }

    private function hasCustomDurationPricing(Plan $plan): bool
    {
        return $plan->isCustomizable()
            && null !== $plan->getMinDurationDays()
            && null !== $plan->getMaxDurationDays()
            && (int) ($plan->getPricePerDay() ?? 0) > 0;
    }
}
