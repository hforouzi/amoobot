<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * A version range as driver manifests write it (docs/design/product-modules.md 4.3): one or more comparators separated
 * by spaces, all of which must hold, for example ">=2.4 <3.0" or "=2.4.1". Operators: >=, >, <=, <, = (or none, which
 * is =). A version may leave out its minor or patch number ("2.4" is 2.4.0). Compared with SemanticVersion.
 */
final class VersionConstraint
{
    private const COMPARATOR = '/^(>=|<=|>|<|=)?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/';

    private function __construct()
    {
    }

    public static function isValid(string $constraint): bool
    {
        return null !== self::parse($constraint);
    }

    /** False for an invalid range or version: nothing is compatible with a range that can't be read. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $comparators = self::parse($constraint);
        if (null === $comparators || !SemanticVersion::isValid($version)) {
            return false;
        }
        foreach ($comparators as [$operator, $bound]) {
            $cmp = SemanticVersion::compare($version, $bound);
            $holds = match ($operator) {
                '>=' => $cmp >= 0,
                '>' => $cmp > 0,
                '<=' => $cmp <= 0,
                '<' => $cmp < 0,
                default => 0 === $cmp,
            };
            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{string, string}>|null [operator, full version] per comparator
     */
    private static function parse(string $constraint): ?array
    {
        $parts = preg_split('/\s+/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $parts) {
            return null;
        }
        $comparators = [];
        foreach ($parts as $part) {
            if (1 !== preg_match(self::COMPARATOR, $part, $m)) {
                return null;
            }
            $comparators[] = [
                '' === $m[1] ? '=' : $m[1],
                sprintf('%d.%d.%d', (int) $m[2], (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)),
            ];
        }

        return $comparators;
    }
}
