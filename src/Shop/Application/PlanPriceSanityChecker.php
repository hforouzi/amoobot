<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Plan;
use App\Payment\Domain\Money\AmountNormalizer;

/**
 * Flags plan prices that look implausible for the shop unit (Toman, see AmountNormalizer).
 *
 * A price entered in Rial is ten times too high; one entered in thousands of Toman or in USD is far
 * too low. Gateways charge exactly what the plan says (Zibal: Toman x 10 Rial), so such a typo
 * reaches customers directly. The check only warns; it never blocks saving.
 */
final class PlanPriceSanityChecker
{
    /** Plausible ranges in Toman: [min, max]. */
    public const PRICE_RANGE = [5_000, 20_000_000];
    public const PRICE_PER_GB_RANGE = [500, 200_000];
    public const PRICE_PER_DAY_RANGE = [100, 1_000_000];

    /**
     * @return list<string> human-readable warnings (empty when everything looks plausible)
     */
    public function warnings(Plan $plan): array
    {
        $warnings = [];
        $this->check($warnings, 'Price', $plan->getPrice(), self::PRICE_RANGE);
        $this->check($warnings, 'Price per GB', $plan->getPricePerGb(), self::PRICE_PER_GB_RANGE);
        $this->check($warnings, 'Price per day', $plan->getPricePerDay(), self::PRICE_PER_DAY_RANGE);

        return $warnings;
    }

    /**
     * @param list<string>   $warnings
     * @param array{int,int} $range
     */
    private function check(array &$warnings, string $label, ?int $value, array $range): void
    {
        // 0 / empty means free or not used.
        if (null === $value || $value <= 0) {
            return;
        }

        [$min, $max] = $range;
        if ($value < $min) {
            $warnings[] = sprintf(
                '%s %s %s looks too low (expected at least %s %s). Prices are in Toman, not thousands of Toman or USD.',
                $label, number_format($value), AmountNormalizer::SHOP_CURRENCY, number_format($min), AmountNormalizer::SHOP_CURRENCY
            );
        } elseif ($value > $max) {
            $warnings[] = sprintf(
                '%s %s %s looks too high (expected at most %s %s). Prices are in Toman; a Rial value is 10x too high (%s Toman?).',
                $label, number_format($value), AmountNormalizer::SHOP_CURRENCY, number_format($max), AmountNormalizer::SHOP_CURRENCY, number_format(intdiv($value, 10))
            );
        }
    }
}
