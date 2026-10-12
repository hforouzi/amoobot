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

        self::assertSame([AmountMatchStatus::MATCH, AmountMatchStatus::OVERPAID], array_values($acceptable));
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
