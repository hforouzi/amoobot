<?php

declare(strict_types=1);

namespace App\Tests\Payment\Money;

use App\Entity\Payment;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\Money\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, ?string, AmountMatchStatus}>
     */
    public static function cases(): iterable
    {
        yield 'exact toman' => ['150000', 'IRT', '150000', 'IRT', AmountMatchStatus::MATCH];
        yield 'rial equals toman x10' => ['150000', 'IRT', '1500000', 'IRR', AmountMatchStatus::MATCH];
        yield 'rial spelled out' => ['150000', 'IRT', '1500000', 'rial', AmountMatchStatus::MATCH];
        yield 'toman passed as rial is underpaid' => ['150000', 'IRT', '150000', 'IRR', AmountMatchStatus::UNDERPAID];
        yield 'underpaid' => ['150000', 'IRT', '149999', 'IRT', AmountMatchStatus::UNDERPAID];
        yield 'overpaid' => ['150000', 'IRT', '1500010', 'IRR', AmountMatchStatus::OVERPAID];
        yield 'missing amount' => ['150000', 'IRT', null, 'IRR', AmountMatchStatus::MISSING];
        yield 'missing currency' => ['150000', 'IRT', '1500000', null, AmountMatchStatus::MISSING];
        yield 'empty strings' => ['150000', 'IRT', '', ' ', AmountMatchStatus::MISSING];
        yield 'garbage amount' => ['150000', 'IRT', '1e6', 'IRR', AmountMatchStatus::MISSING];
        yield 'negative amount' => ['150000', 'IRT', '-1500000', 'IRR', AmountMatchStatus::MISSING];
        yield 'wrong currency' => ['150000', 'IRT', '150000', 'USD', AmountMatchStatus::CURRENCY_MISMATCH];
        yield 'crypto exact' => ['12.5', 'USD', '12.50', 'usd', AmountMatchStatus::MATCH];
        yield 'crypto partial' => ['12.5', 'USD', '6.25', 'USD', AmountMatchStatus::UNDERPAID];
        yield 'crypto wrong fiat' => ['12.5', 'USD', '12.5', 'EUR', AmountMatchStatus::CURRENCY_MISMATCH];
        yield 'thousand separators' => ['150000', 'IRT', '1,500,000', 'IRR', AmountMatchStatus::MATCH];
    }

    #[DataProvider('cases')]
    public function testMatch(string $expected, string $expectedCurrency, ?string $actual, ?string $actualCurrency, AmountMatchStatus $status): void
    {
        $match = (new AmountMatcher())->match($expected, $expectedCurrency, $actual, $actualCurrency);

        self::assertSame($status, $match->status, $match->describe());
    }

    public function testOnlyMatchAndOverpaidAreAcceptable(): void
    {
        $acceptable = array_filter(AmountMatchStatus::cases(), static fn (AmountMatchStatus $s): bool => $s->isAcceptable());

        self::assertSame([AmountMatchStatus::MATCH, AmountMatchStatus::OVERPAID, AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE], array_values($acceptable));
    }

    public function testExpectationDefaultsToPayableAmountInToman(): void
    {
        $payment = (new Payment())->setAmount(200000)->setPayableAmount(180000);

        self::assertSame(['180000', 'IRT'], AmountMatcher::expectationFor($payment));
    }

    public function testStoredQuoteOverridesShopAmount(): void
    {
        $payment = (new Payment())->setAmount(200000)->setExpectation('4.1', 'usd');

        self::assertSame(['4.1', 'USD'], AmountMatcher::expectationFor($payment));
    }

    public function testNormalizerConvertsRialToToman(): void
    {
        self::assertSame(1500000, AmountNormalizer::toRial(150000));
        self::assertSame(['amount' => '150000.5', 'currency' => 'IRT'], AmountNormalizer::normalize('1500005', 'IRR'));
        self::assertSame(['amount' => '0.5', 'currency' => 'IRT'], AmountNormalizer::normalize('5', 'IRR'));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, AmountMatchStatus}>
     */
    public static function toleranceCases(): iterable
    {
        yield 'crypto 1% exactly at the limit' => ['10', 'USD', '9.9', 'USD', '1', AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE];
        yield 'crypto just beyond 1%' => ['10', 'USD', '9.8999', 'USD', '1', AmountMatchStatus::UNDERPAID];
        yield 'crypto half percent' => ['12.345678', 'USD', '12.3', 'usd', '0.5', AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE];
        yield 'fiat default 0: one Rial short' => ['150000', 'IRT', '1499999', 'IRR', '0', AmountMatchStatus::UNDERPAID];
        yield 'tolerance never excuses a currency mismatch' => ['10', 'USD', '10', 'EUR', '5', AmountMatchStatus::CURRENCY_MISMATCH];
        yield 'tolerance never excuses missing amounts' => ['10', 'USD', '', 'USD', '5', AmountMatchStatus::MISSING];
        yield 'exact stays a match' => ['10', 'USD', '10', 'USD', '1', AmountMatchStatus::MATCH];
        yield 'rial vs toman with tolerance' => ['150000', 'IRT', '1485000', 'IRR', '1', AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE];
    }

    #[DataProvider('toleranceCases')]
    public function testTolerance(string $expected, string $expectedCurrency, string $actual, string $actualCurrency, string $tolerance, AmountMatchStatus $status): void
    {
        $match = (new AmountMatcher())->match($expected, $expectedCurrency, $actual, $actualCurrency, $tolerance);

        self::assertSame($status, $match->status, $match->describe());
    }

    public function testShortfallIsDescribed(): void
    {
        $match = (new AmountMatcher())->match('10', 'USD', '9.5', 'USD', '1');

        self::assertSame(AmountMatchStatus::UNDERPAID, $match->status);
        self::assertSame('0.5', $match->shortfall);
        self::assertSame('underpaid: expected 10 USD, gateway reported 9.5 USD; shortfall 0.5 USD (5%), tolerance 1%', $match->describe());
    }

    public function testInvalidToleranceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AmountMatcher())->match('10', 'USD', '10', 'USD', '-1');
    }

    public function testDecimalArithmetic(): void
    {
        self::assertSame('0.5', Decimal::subtract('10', '9.5'));
        self::assertSame('0.0001', Decimal::subtract('9.9', '9.8999'));
        self::assertSame('0', Decimal::subtract('12.50', '12.5'));
        self::assertSame('1', Decimal::subtract('1000000000000000000001', '1000000000000000000000'));
        self::assertSame('12.345678', Decimal::multiply('12345.678', '0.001'));
        self::assertSame('150000000', Decimal::multiply('1500000', '100'));
        self::assertSame('0', Decimal::multiply('0', '99.9'));
        self::assertSame('9999999999999999999800000000000000000001', Decimal::multiply('99999999999999999999', '99999999999999999999'));
    }

    public function testDecimalCompare(): void
    {
        self::assertSame(0, Decimal::compare('10', '10.000'));
        self::assertSame(1, Decimal::compare('10.01', '10'));
        self::assertSame(-1, Decimal::compare('9.99', '10'));
        self::assertSame(1, Decimal::compare('100', '99.999'));
        self::assertSame('12.5', Decimal::normalize(12.5));
        self::assertNull(Decimal::normalize('abc'));
    }
}
