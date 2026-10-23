<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

/**
 * Minimal exact decimal-string helpers (no floats in comparisons, no bcmath dependency).
 */
final class Decimal
{
    /**
     * Returns a canonical decimal string ("12", "0.5", "1000.25") or null when the input is not a
     * plain non-negative decimal number.
     */
    public static function normalize(string|int|float $value): ?string
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $value = sprintf('%.10F', $value);
        }

        $text = str_replace([',', '_', ' '], '', trim((string) $value));
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $m)) {
            return null;
        }

        $integer = ltrim($m[1], '0');
        $fraction = rtrim($m[2] ?? '', '0');

        return ('' === $integer ? '0' : $integer).('' === $fraction ? '' : '.'.$fraction);
    }

    /**
     * Compares two canonical decimal strings: -1, 0 or 1.
     */
    public static function compare(string $a, string $b): int
    {
        [$ai, $af] = self::split($a);
        [$bi, $bf] = self::split($b);

        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) <=> strlen($bi);
        }
        $cmp = strcmp($ai, $bi);
        if (0 !== $cmp) {
            return $cmp <=> 0;
        }

        $len = max(strlen($af), strlen($bf));

        return strcmp(str_pad($af, $len, '0'), str_pad($bf, $len, '0')) <=> 0;
    }

    /**
     * floor($whole * $numerator / $denominator), exactly, capped at $whole and never negative: the largest x in
     * [0, $whole] with x * $denominator <= $whole * $numerator (binary search over exact products, no division).
     *
     * @param int    $whole       a non-negative integer (e.g. an amount in Toman)
     * @param string $numerator   a canonical non-negative decimal
     * @param string $denominator a canonical decimal greater than zero
     */
    public static function floorShare(int $whole, string $numerator, string $denominator): int
    {
        $numerator = self::normalize($numerator);
        $denominator = self::normalize($denominator);
        if ($whole < 0 || null === $numerator || null === $denominator || 0 === self::compare($denominator, '0')) {
            throw new \InvalidArgumentException('floorShare() needs a non-negative whole, a non-negative numerator and a positive denominator.');
        }

        $target = self::multiply((string) $whole, $numerator);
        [$low, $high] = [0, $whole];
        while ($low < $high) {
            $middle = $low + intdiv($high - $low + 1, 2);
            if (self::compare(self::multiply((string) $middle, $denominator), $target) <= 0) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    public static function divideByPowerOfTen(string $decimal, int $power): string
    {
        [$integer, $fraction] = self::split($decimal);
        $integer = str_pad($integer, $power + 1, '0', STR_PAD_LEFT);

        return (string) self::normalize(substr($integer, 0, -$power).'.'.substr($integer, -$power).$fraction);
    }

    /**
     * $a - $b for canonical decimal strings with $a >= $b.
     */
    public static function subtract(string $a, string $b): string
    {
        if (self::compare($a, $b) < 0) {
            throw new \InvalidArgumentException('Decimal::subtract() needs $a >= $b.');
        }
        [$ai, $af] = self::split($a);
        [$bi, $bf] = self::split($b);
        $scale = max(strlen($af), strlen($bf));
        $x = $ai.str_pad($af, $scale, '0');
        $y = str_pad($bi.str_pad($bf, $scale, '0'), strlen($x), '0', STR_PAD_LEFT);

        $digits = '';
        $borrow = 0;
        for ($i = strlen($x) - 1; $i >= 0; --$i) {
            $d = (int) $x[$i] - (int) $y[$i] - $borrow;
            $borrow = $d < 0 ? 1 : 0;
            $digits = (string) ($d + 10 * $borrow).$digits;
        }

        return self::fromScaled($digits, $scale);
    }

    public static function multiply(string $a, string $b): string
    {
        [$ai, $af] = self::split($a);
        [$bi, $bf] = self::split($b);
        $x = ltrim($ai.$af, '0');
        $y = ltrim($bi.$bf, '0');
        if ('' === $x || '' === $y) {
            return '0';
        }

        $result = array_fill(0, strlen($x) + strlen($y), 0);
        for ($i = strlen($x) - 1; $i >= 0; --$i) {
            for ($j = strlen($y) - 1; $j >= 0; --$j) {
                $position = $i + $j + 1;
                $sum = (int) $x[$i] * (int) $y[$j] + $result[$position];
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return self::fromScaled(implode('', $result), strlen($af) + strlen($bf));
    }

    /**
     * Digits of an integer that represents a value scaled by 10^$scale.
     */
    private static function fromScaled(string $digits, int $scale): string
    {
        if (0 === $scale) {
            return (string) self::normalize($digits);
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        return (string) self::normalize(substr($digits, 0, -$scale).'.'.substr($digits, -$scale));
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $decimal): array
    {
        $parts = explode('.', $decimal, 2);
        $integer = ltrim($parts[0], '0');

        return ['' === $integer ? '0' : $integer, $parts[1] ?? ''];
    }
}
