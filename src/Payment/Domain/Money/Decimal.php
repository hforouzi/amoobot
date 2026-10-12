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

    public static function divideByPowerOfTen(string $decimal, int $power): string
    {
        [$integer, $fraction] = self::split($decimal);
        $integer = str_pad($integer, $power + 1, '0', STR_PAD_LEFT);

        return (string) self::normalize(substr($integer, 0, -$power).'.'.substr($integer, -$power).$fraction);
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
