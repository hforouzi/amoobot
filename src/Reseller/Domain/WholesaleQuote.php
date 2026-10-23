<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * What a reseller pays for one order, and how it was reached (ResellerPricing). Integer Toman; percentages in basis
 * points (1 bp = 0.01 %). The order stores it as its price snapshot (metadata.priceSnapshot.reseller) and in its
 * wholesale_amount / retail_amount / reseller_floor_amount columns.
 */
final class WholesaleQuote
{
    public const RULE_RESELLER = 'reseller';
    public const RULE_PLAN_PERCENT = 'plan_percent';
    public const RULE_PLAN_FIXED = 'plan_fixed';

    public function __construct(
        /** The list price before any discount (the plan's, the add-on's). */
        public readonly int $baseAmount,
        /** What a retail customer pays now: the base after the global discount, no code. */
        public readonly int $retailAmount,
        public readonly string $rule,
        /** The rule's discount, or null for a fixed price. */
        public readonly ?int $discountBp,
        /** The rule's price after the discount (or the fixed price), rounded up. */
        public readonly int $candidateAmount,
        public readonly int $floorAmount,
        public readonly bool $floorApplied,
        public readonly bool $retailApplied,
        public readonly int $roundedTo,
        /** What the reseller pays: min(max(candidate, floor), retail). */
        public readonly int $wholesaleAmount,
    ) {
    }

    /**
     * The order's metadata.priceSnapshot.reseller block.
     *
     * @return array<string, int|string|bool|null>
     */
    public function snapshot(int $resellerId): array
    {
        return [
            'resellerId' => $resellerId,
            'baseAmount' => $this->baseAmount,
            'retailAmount' => $this->retailAmount,
            'rule' => $this->rule,
            'discountBp' => $this->discountBp,
            'candidateAmount' => $this->candidateAmount,
            'floorAmount' => $this->floorAmount,
            'floorApplied' => $this->floorApplied,
            'retailApplied' => $this->retailApplied,
            'roundedTo' => $this->roundedTo,
            'wholesaleAmount' => $this->wholesaleAmount,
        ];
    }

    /** Retail value minus wholesale: the discount the shop gives (not the reseller's real profit, which is unknown). */
    public function discountGiven(): int
    {
        return max(0, $this->retailAmount - $this->wholesaleAmount);
    }
}
