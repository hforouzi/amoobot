<?php

declare(strict_types=1);

namespace App\MiniApp\Reseller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reading the reseller screens' input exactly: JSON bodies only, whole numbers by an anchored pattern (/D: no trailing
 * newline), nothing trimmed or coerced. A value that does not match is refused (InvalidResellerInput), never read as
 * something else.
 */
final class ResellerInput
{
    /** A positive whole number of at most 6 digits (GB, days), as JSON text or a JSON integer. */
    private const NUMBER = '/^[1-9][0-9]{0,5}$/D';
    /** An id as a path / query / body value. */
    public const ID = '/^[1-9][0-9]{0,9}$/D';

    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return [];
        }
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * An optional count (traffic GB, days): 0 when the field is absent or empty.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidResellerInput
     */
    public static function optionalNumber(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (null === $value || '' === $value) {
            return 0;
        }
        if (is_int($value) && $value > 0 && $value <= 999999) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match(self::NUMBER, $value)) {
            return (int) $value;
        }

        throw new InvalidResellerInput($key);
    }

    /**
     * A required id.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidResellerInput
     */
    public static function id(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (is_int($value) && $value > 0 && $value <= 9999999999) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match(self::ID, $value)) {
            return (int) $value;
        }

        throw new InvalidResellerInput($key);
    }

    /**
     * A query parameter that must be one of $allowed when present ($default when absent).
     *
     * @param list<string> $allowed
     *
     * @throws InvalidResellerInput
     */
    public static function choice(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query->get($key);
        if (null === $value || '' === $value) {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw new InvalidResellerInput($key);
        }

        return $value;
    }

    /**
     * ?page=N (1 when absent).
     *
     * @throws InvalidResellerInput
     */
    public static function page(Request $request): int
    {
        $value = $request->query->get('page');
        if (null === $value || '' === $value) {
            return 1;
        }
        if (1 !== preg_match('/^[1-9][0-9]{0,3}$/D', (string) $value)) {
            throw new InvalidResellerInput('page');
        }

        return (int) $value;
    }

    /**
     * An optional id in the query (0 when absent).
     *
     * @throws InvalidResellerInput
     */
    public static function queryId(Request $request, string $key): int
    {
        $value = $request->query->get($key);
        if (null === $value || '' === $value) {
            return 0;
        }
        if (1 !== preg_match(self::ID, (string) $value)) {
            throw new InvalidResellerInput($key);
        }

        return (int) $value;
    }

    /**
     * The search text (?q=), as typed: 1..64 characters, no control characters, no space at either end (a search is
     * never silently changed); null when absent.
     *
     * @throws InvalidResellerInput
     */
    public static function search(Request $request): ?string
    {
        $value = $request->query->get('q');
        if (null === $value || '' === $value) {
            return null;
        }
        $value = (string) $value;
        if (1 !== preg_match('/^[^\p{Cc}\s](?:[^\p{Cc}]{0,62}[^\p{Cc}\s])?$/uD', $value)) {
            throw new InvalidResellerInput('q');
        }

        return $value;
    }
}
