<?php

declare(strict_types=1);

namespace App\Tests\Payment\Money;

use App\Payment\Domain\Money\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decimal::floorShare(): floor(whole * numerator / denominator), exact, capped at whole, never negative. Used for the
 * wallet credit of a top-up paid in another currency or below the ordered amount.
 */
final class DecimalFloorShareTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string, int}>
     */
    public static function shares(): iterable
    {
        yield 'all of it' => [50000, '1', '1', 50000];
        yield 'half' => [50000, '0.5', '1', 25000];
        yield 'crypto 0.5% short' => [50000, '0.995', '1', 49750];
        yield 'floors, never rounds up' => [100, '1', '3', 33];
        yield 'two thirds floors' => [100, '2', '3', 66];
        yield 'many decimals' => [150000, '12.345678901234567891', '12.345679', 149999];
        yield 'more than expected is capped' => [50000, '60000', '50000', 50000];
        yield 'nothing received' => [50000, '0', '1', 0];
        yield 'big amounts' => [1_000_000_000_000, '999999.999999', '1000000', 999_999_999_999];
        yield 'nothing ordered' => [0, '1', '1', 0];
    }

    #[DataProvider('shares')]
    public function testItIsTheExactFloorOfTheShare(int $whole, string $numerator, string $denominator, int $expected): void
    {
        self::assertSame($expected, Decimal::floorShare($whole, $numerator, $denominator));
    }

    public function testADenominatorOfZeroIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::floorShare(100, '1', '0');
    }
}
