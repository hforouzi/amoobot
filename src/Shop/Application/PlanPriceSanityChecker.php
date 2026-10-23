<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Plan;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Reseller\Application\ResellerPricing;
use App\Reseller\Application\ResellerSettings;

/**
 * Flags plan prices that look implausible for the shop unit (Toman, see AmountNormalizer).
 *
 * A price entered in Rial is ten times too high; one entered in thousands of Toman or in USD is far
 * too low. Gateways charge exactly what the plan says (Zibal: Toman x 10 Rial), so such a typo
 * reaches customers directly. With resellers on, it also names the reseller rules the floor overrides
 * (ResellerPricing::clippedRules). The check only warns; it never blocks saving.
 */
final class PlanPriceSanityChecker
{
    /** Optional so the unit tests can build it bare; the container always gives both. */
    public function __construct(
        private readonly ?ResellerPricing $resellerPricing = null,
        private readonly ?ResellerSettings $resellerSettings = null,
    ) {
    }

    /** Plausible ranges in Toman: [min, max]. */
    public const PRICE_RANGE = [5_000, 20_000_000];
    public const PRICE_PER_GB_RANGE = [500, 200_000];
    public const PRICE_PER_DAY_RANGE = [100, 1_000_000];

    /**
     * @return list<string> human-readable warnings (empty when everything looks plausible)
     */
    public function warnings(Plan $plan): array
    {
        $labels = ['price' => 'Price', 'pricePerGb' => 'Price per GB', 'pricePerDay' => 'Price per day'];
        $warnings = [];
        foreach ($this->issues($plan) as $issue) {
            $label = $labels[$issue['field']];
            $warnings[] = 'low' === $issue['kind']
                ? sprintf(
                    '%s %s %s looks too low (expected at least %s %s). Prices are in Toman, not thousands of Toman or USD.',
                    $label, number_format($issue['value']), AmountNormalizer::SHOP_CURRENCY, number_format($issue['min']), AmountNormalizer::SHOP_CURRENCY
                )
                : sprintf(
                    '%s %s %s looks too high (expected at most %s %s). Prices are in Toman; a Rial value is 10x too high (%s Toman?).',
                    $label, number_format($issue['value']), AmountNormalizer::SHOP_CURRENCY, number_format($issue['max']), AmountNormalizer::SHOP_CURRENCY, number_format(intdiv($issue['value'], 10))
                );
        }

        // A reseller rule this plan's floor overrides: the reseller pays the floor, not what his rule says.
        if (null !== $this->resellerPricing && true === $this->resellerSettings?->enabled()) {
            foreach ($this->resellerPricing->clippedRules($plan) as $clipped) {
                $warnings[] = sprintf(
                    'Reseller "%s": the wholesale rule (%s) gives %s %s, below the reseller floor %s %s; the floor price is charged.',
                    (string) $clipped['reseller'], $clipped['rule'], number_format($clipped['candidate']), AmountNormalizer::SHOP_CURRENCY, number_format($clipped['floor']), AmountNormalizer::SHOP_CURRENCY
                );
            }
        }

        return $warnings;
    }

    /**
     * The same findings as data, for pages that word them in their own language (the Mini App).
     *
     * @return list<array{field: 'price'|'pricePerGb'|'pricePerDay', kind: 'low'|'high', value: int, min: int, max: int}>
     */
    public function issues(Plan $plan): array
    {
        $issues = [];
        foreach ([['price', $plan->getPrice(), self::PRICE_RANGE], ['pricePerGb', $plan->getPricePerGb(), self::PRICE_PER_GB_RANGE], ['pricePerDay', $plan->getPricePerDay(), self::PRICE_PER_DAY_RANGE]] as [$field, $value, [$min, $max]]) {
            // 0 / empty means free or not used.
            if (null === $value || $value <= 0 || ($value >= $min && $value <= $max)) {
                continue;
            }
            $issues[] = ['field' => $field, 'kind' => $value < $min ? 'low' : 'high', 'value' => $value, 'min' => $min, 'max' => $max];
        }

        return $issues;
    }
}
