<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Reseller\Application\ResellerPricing;
use App\Reseller\Domain\WholesaleQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wholesale formula itself (docs/design/reseller-and-claim.md, 2.2), integer arithmetic:
 * wholesale = min(max(ceil_to(base - base * bp / 10000, rounding), floor), retail),
 * floor = max(base * floor_bp / 10000, plan floor amount).
 */
final class ResellerPricingFormulaTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, string, ?int, ?int, int, int, int, int, bool, bool}>
     */
    public static function cases(): iterable
    {
        // base, retail, rule, bp, fixed, floor_bp, plan floor, rounding => wholesale, floorApplied, retailApplied
        yield 'percent, already round' => [150000, 150000, WholesaleQuote::RULE_RESELLER, 2000, null, 5000, 0, 1000, 120000, false, false];
        yield 'rounded up, never down' => [150000, 150000, WholesaleQuote::RULE_RESELLER, 1250, null, 5000, 0, 1000, 132000, false, false];
        yield 'one Toman over a step goes to the next step' => [100001, 200000, WholesaleQuote::RULE_RESELLER, 0, null, 0, 0, 1000, 101000, false, false];
        yield 'rounding 1 keeps the exact price' => [150000, 150000, WholesaleQuote::RULE_RESELLER, 1250, null, 5000, 0, 1, 131250, false, false];
        yield 'floor wins over a deep discount' => [100000, 100000, WholesaleQuote::RULE_RESELLER, 9000, null, 5000, 0, 1000, 50000, true, false];
        yield 'floor checked after rounding (rounded price is above it)' => [100000, 100000, WholesaleQuote::RULE_RESELLER, 5050, null, 5000, 0, 1000, 50000, false, false];
        yield 'plan floor amount above the percent floor' => [100000, 100000, WholesaleQuote::RULE_PLAN_PERCENT, 4000, null, 5000, 70000, 1000, 70000, true, false];
        yield 'plan floor amount below the percent floor' => [100000, 100000, WholesaleQuote::RULE_PLAN_PERCENT, 9000, null, 5000, 20000, 1000, 50000, true, false];
        yield 'fixed price' => [150000, 150000, WholesaleQuote::RULE_PLAN_FIXED, null, 99500, 5000, 0, 1000, 100000, false, false];
        yield 'fixed price under the floor' => [150000, 150000, WholesaleQuote::RULE_PLAN_FIXED, null, 10000, 5000, 0, 1000, 75000, true, false];
        yield 'promotion: never more than retail' => [100000, 60000, WholesaleQuote::RULE_RESELLER, 2000, null, 5000, 0, 1000, 60000, false, true];
        yield 'promotion below the floor: retail still wins' => [100000, 40000, WholesaleQuote::RULE_RESELLER, 2000, null, 5000, 0, 1000, 40000, false, true];
        yield 'no discount equals the base' => [100000, 100000, WholesaleQuote::RULE_RESELLER, 0, null, 5000, 0, 1000, 100000, false, false];
        yield 'zero base' => [0, 0, WholesaleQuote::RULE_RESELLER, 2000, null, 5000, 0, 1000, 0, false, false];
        yield 'large amounts stay integer' => [900_000_000_000, 900_000_000_000, WholesaleQuote::RULE_RESELLER, 3333, null, 5000, 0, 1000, 600_030_000_000, false, false];
    }

    #[DataProvider('cases')]
    public function testTheFormula(int $base, int $retail, string $rule, ?int $bp, ?int $fixed, int $floorBp, int $planFloor, int $rounding, int $wholesale, bool $floorApplied, bool $retailApplied): void
    {
        $quote = ResellerPricing::compute($base, $retail, $rule, $bp, $fixed, $floorBp, $planFloor, $rounding);

        self::assertSame($wholesale, $quote->wholesaleAmount);
        self::assertSame($floorApplied, $quote->floorApplied, 'floor applied');
        self::assertSame($retailApplied, $quote->retailApplied, 'retail applied');
        self::assertSame(0, $quote->candidateAmount % $rounding, 'the candidate is a multiple of the rounding');
        self::assertGreaterThanOrEqual(min($quote->floorAmount, $retail), $quote->wholesaleAmount, 'never below min(floor, retail)');
        self::assertLessThanOrEqual($retail, $quote->wholesaleAmount, 'never above retail');
    }

    public function testTheSnapshotCarriesHowThePriceWasReached(): void
    {
        $quote = ResellerPricing::compute(100000, 90000, WholesaleQuote::RULE_PLAN_PERCENT, 9000, null, 5000, 0, 1000);

        self::assertSame([
            'resellerId' => 7,
            'baseAmount' => 100000,
            'retailAmount' => 90000,
            'rule' => 'plan_percent',
            'discountBp' => 9000,
            'candidateAmount' => 10000,
            'floorAmount' => 50000,
            'floorApplied' => true,
            'retailApplied' => false,
            'roundedTo' => 1000,
            'wholesaleAmount' => 50000,
        ], $quote->snapshot(7));
        self::assertSame(40000, $quote->discountGiven());
    }
}
