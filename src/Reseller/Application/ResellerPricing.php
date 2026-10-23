<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Entity\VpnService;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\ResellerStatus;
use App\Reseller\Domain\WholesaleQuote;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\RenewalPriceResult;
use App\Shop\Application\TrafficAddonPricingService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY place a wholesale price is computed (docs/design/reseller-and-claim.md, section 2.2), for the bot and the
 * Mini App alike. The client sends ids and options, never an amount. Integer arithmetic:
 *
 *   base      = the list price before any discount (PlanPricingService / TrafficAddonPricingService)
 *   retail    = base after the global discount, no code (what a retail customer pays now)
 *   candidate = base - intdiv(base * rule_bp, 10000)   (or the plan's fixed price), rounded UP to reseller.rounding
 *   floor     = max(intdiv(base * reseller.floor_bp, 10000), plan.reseller_floor_amount ?? 0)
 *   wholesale = min(max(candidate, floor), retail)
 *
 * The rule is the reseller's per-plan override (percent; or a fixed price, fixed plans only) or else the reseller's
 * default discount. Discount codes and the global discount never stack on top: the wholesale price starts from the
 * base, and min(…, retail) only keeps a reseller from paying more than a customer during a promotion. Rounding goes
 * up and comes before the floor, so it can never take the price under the floor. plan.reseller_floor_amount applies
 * to the plan itself (new service, renewal) of a fixed plan; add-traffic and customizable plans use the percent floor.
 */
class ResellerPricing
{
    /** A nominal base for checking a percent rule against the floor on a customizable plan (no single price). */
    private const NOMINAL_BASE = 1_000_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanPricingService $planPricing,
        private readonly TrafficAddonPricingService $trafficAddonPricing,
        private readonly ResellerSettings $settings,
    ) {
    }

    /**
     * The formula itself (pure).
     *
     * @param string   $rule        WholesaleQuote::RULE_*
     * @param int|null $discountBp  the percent rule's discount (null for a fixed price)
     * @param int|null $fixedAmount the fixed price (RULE_PLAN_FIXED only)
     */
    public static function compute(int $baseAmount, int $retailAmount, string $rule, ?int $discountBp, ?int $fixedAmount, int $floorBp, int $planFloorAmount, int $rounding): WholesaleQuote
    {
        $baseAmount = max(0, $baseAmount);
        $retailAmount = max(0, $retailAmount);
        $rounding = max(1, $rounding);
        $raw = WholesaleQuote::RULE_PLAN_FIXED === $rule
            ? max(0, (int) $fixedAmount)
            : $baseAmount - intdiv($baseAmount * max(0, min(10000, (int) $discountBp)), 10000);
        $candidate = self::ceilTo($raw, $rounding);
        $floor = max(intdiv($baseAmount * max(0, min(10000, $floorBp)), 10000), max(0, $planFloorAmount));
        $afterFloor = max($candidate, $floor);
        $wholesale = min($afterFloor, $retailAmount);

        return new WholesaleQuote(
            baseAmount: $baseAmount,
            retailAmount: $retailAmount,
            rule: $rule,
            discountBp: WholesaleQuote::RULE_PLAN_FIXED === $rule ? null : (int) $discountBp,
            candidateAmount: $candidate,
            floorAmount: $floor,
            floorApplied: $candidate < $floor,
            retailApplied: $afterFloor > $retailAmount,
            roundedTo: $rounding,
            wholesaleAmount: $wholesale,
        );
    }

    /**
     * A new service of this plan (customizable plans: with the chosen traffic and days).
     *
     * @throws ResellerException price_unavailable
     */
    public function quoteNewService(Reseller $reseller, Plan $plan, ?int $trafficGb = null, ?int $durationDays = null): WholesaleQuote
    {
        $price = $this->planPricing->calculateNewOrderPrice($plan, array_filter(['trafficGb' => $trafficGb, 'durationDays' => $durationDays], static fn (?int $v): bool => null !== $v));

        return $this->planQuote($reseller, $plan, $price->baseAmount, $price->afterGlobalDiscountAmount);
    }

    /**
     * Renewing a service at wholesale: today's retail renewal (current plan price, the source order's traffic and
     * duration), then the reseller's rule for that plan.
     *
     * @return array{WholesaleQuote, RenewalPriceResult, Plan}
     *
     * @throws ResellerException price_unavailable
     */
    public function quoteRenewal(Reseller $reseller, VpnService $service): array
    {
        $plan = $service->getOrder()?->getPlan();
        $renewal = $plan instanceof Plan ? $this->planPricing->calculateRenewalAmount($service, $plan) : null;
        if (!$plan instanceof Plan || !$renewal instanceof RenewalPriceResult) {
            throw new ResellerException('price_unavailable');
        }

        return [$this->planQuote($reseller, $plan, $renewal->baseAmount, $renewal->afterGlobalDiscountAmount), $renewal, $plan];
    }

    /**
     * Extra traffic at wholesale: the add-on price per GB, the reseller's percent for the service's plan (a fixed
     * price is for the plan itself, not for add-ons) or the reseller's default; the percent floor only.
     *
     * @throws ResellerException price_unavailable
     */
    public function quoteAddTraffic(Reseller $reseller, VpnService $service, int $trafficGb): WholesaleQuote
    {
        $price = $this->trafficAddonPricing->calculate($trafficGb);
        $plan = $service->getOrder()?->getPlan();
        $override = $plan instanceof Plan ? $this->override($reseller, $plan) : null;
        [$rule, $discountBp] = null !== $override?->getDiscountBp()
            ? [WholesaleQuote::RULE_PLAN_PERCENT, $override->getDiscountBp()]
            : [WholesaleQuote::RULE_RESELLER, $reseller->getDiscountBp()];

        return $this->checked(self::compute($price->baseAmount, $price->afterGlobalDiscountAmount, $rule, $discountBp, null, $this->settings->floorBp(), 0, $this->settings->rounding()));
    }

    /** The reseller's own rule for this plan, if any. */
    public function override(Reseller $reseller, Plan $plan): ?ResellerPlanPrice
    {
        if (null === $reseller->getId() || null === $plan->getId()) {
            return null;
        }
        $override = $this->entityManager->getRepository(ResellerPlanPrice::class)->findOneBy(['reseller' => $reseller, 'plan' => $plan]);

        return $override instanceof ResellerPlanPrice ? $override : null;
    }

    /**
     * The active resellers' rules for this plan that the floor overrides (their price would be lower than the floor),
     * for PlanPriceSanityChecker and the reseller page. A fixed plan is checked at its price; a customizable one at a
     * nominal base (only the percent can be checked there).
     *
     * @return list<array{reseller: Reseller, rule: string, candidate: int, floor: int}>
     */
    public function clippedRules(Plan $plan): array
    {
        if (null === $plan->getId()) {
            return [];
        }
        $overrides = [];
        foreach ($this->entityManager->getRepository(ResellerPlanPrice::class)->findBy(['plan' => $plan]) as $override) {
            $overrides[(int) $override->getReseller()->getId()] = $override;
        }
        $clipped = [];
        foreach ($this->entityManager->getRepository(Reseller::class)->findBy(['status' => ResellerStatus::ACTIVE], ['id' => 'ASC']) as $reseller) {
            $quote = $this->ruleQuote($reseller, $plan, $overrides[(int) $reseller->getId()] ?? null);
            if ($quote->floorApplied) {
                $clipped[] = ['reseller' => $reseller, 'rule' => $quote->rule, 'candidate' => $quote->candidateAmount, 'floor' => $quote->floorAmount];
            }
        }

        return $clipped;
    }

    /** Whether this rule would be clipped by the floor on this plan (the reseller page's warning). */
    public function isClipped(Reseller $reseller, Plan $plan, ?ResellerPlanPrice $override): bool
    {
        return $this->ruleQuote($reseller, $plan, $override)->floorApplied;
    }

    /**
     * The price paid for an order is the snapshot taken when it was created (the wholesale amount on the order), not
     * a new quote: section 2.4. Exposed for the reconcile wording and the admin page.
     */
    public static function snapshotOf(Order $order): ?array
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $snapshot = $metadata['priceSnapshot']['reseller'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    private function planQuote(Reseller $reseller, Plan $plan, int $baseAmount, int $retailAmount): WholesaleQuote
    {
        [$rule, $discountBp, $fixedAmount] = $this->rule($reseller, $plan, $this->override($reseller, $plan));
        $planFloor = $plan->isCustomizable() ? 0 : (int) ($plan->getResellerFloorAmount() ?? 0);

        return $this->checked(self::compute($baseAmount, $retailAmount, $rule, $discountBp, $fixedAmount, $this->settings->floorBp(), $planFloor, $this->settings->rounding()));
    }

    private function ruleQuote(Reseller $reseller, Plan $plan, ?ResellerPlanPrice $override): WholesaleQuote
    {
        [$rule, $discountBp, $fixedAmount] = $this->rule($reseller, $plan, $override);
        $base = $plan->isCustomizable() ? self::NOMINAL_BASE : max(0, $plan->getPrice());
        $planFloor = $plan->isCustomizable() ? 0 : (int) ($plan->getResellerFloorAmount() ?? 0);

        // Retail is not limiting here: the question is only whether the floor wins over the rule.
        return self::compute($base, PHP_INT_MAX, $rule, $discountBp, $fixedAmount, $this->settings->floorBp(), $planFloor, $this->settings->rounding());
    }

    /**
     * @return array{string, int|null, int|null} rule, discount (bp), fixed price
     */
    private function rule(Reseller $reseller, Plan $plan, ?ResellerPlanPrice $override): array
    {
        if (null !== $override?->getFixedAmount() && !$plan->isCustomizable()) {
            return [WholesaleQuote::RULE_PLAN_FIXED, null, $override->getFixedAmount()];
        }
        if (null !== $override?->getDiscountBp()) {
            return [WholesaleQuote::RULE_PLAN_PERCENT, $override->getDiscountBp(), null];
        }

        return [WholesaleQuote::RULE_RESELLER, $reseller->getDiscountBp(), null];
    }

    /**
     * @throws ResellerException price_unavailable (nothing to sell at a zero price, like a broken plan today)
     */
    private function checked(WholesaleQuote $quote): WholesaleQuote
    {
        if ($quote->baseAmount <= 0 || $quote->wholesaleAmount <= 0) {
            throw new ResellerException('price_unavailable');
        }

        return $quote;
    }

    private static function ceilTo(int $amount, int $step): int
    {
        return $amount <= 0 ? 0 : intdiv($amount + $step - 1, $step) * $step;
    }
}
