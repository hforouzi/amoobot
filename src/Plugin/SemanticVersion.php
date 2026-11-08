<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Semantic Versioning 2.0.0 precedence (semver.org, section 11): MAJOR.MINOR.PATCH numerically, a pre-release is lower
 * than its release, build metadata is ignored.
 */
final class SemanticVersion
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    public static function isValid(string $version): bool
    {
        return 1 === preg_match(self::PATTERN, $version);
    }

    /**
     * Below zero, zero or above zero as $a is lower than, equal to or greater than $b; null when either is not semver.
     */
    public static function compare(string $a, string $b): ?int
    {
        if (1 !== preg_match(self::PATTERN, $a, $ma) || 1 !== preg_match(self::PATTERN, $b, $mb)) {
            return null;
        }
        for ($i = 1; $i <= 3; ++$i) {
            $cmp = self::compareNumeric($ma[$i], $mb[$i]);
            if (0 !== $cmp) {
                return $cmp;
            }
        }
        $preA = $ma[4] ?? '';
        $preB = $mb[4] ?? '';
        if ('' === $preA || '' === $preB) {
            // No pre-release is greater than any pre-release.
            return ('' === $preA ? 1 : 0) - ('' === $preB ? 1 : 0);
        }

        $idsA = explode('.', $preA);
        $idsB = explode('.', $preB);
        $count = min(count($idsA), count($idsB));
        for ($i = 0; $i < $count; ++$i) {
            $numA = ctype_digit($idsA[$i]);
            $numB = ctype_digit($idsB[$i]);
            $cmp = match (true) {
                $numA && $numB => self::compareNumeric($idsA[$i], $idsB[$i]),
                $numA => -1,
                $numB => 1,
                default => strcmp($idsA[$i], $idsB[$i]) <=> 0,
            };
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return count($idsA) <=> count($idsB);
    }

    /** Digit strings of any length, without an integer overflow. */
    private static function compareNumeric(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }
}
