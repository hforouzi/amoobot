<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

final class AdminJsonFormatter
{
    /** Keys whose values are never shown (at any depth, any case): tokens, secrets, passwords, API keys, auth headers. */
    private const SENSITIVE_KEY = '/token|secret|passw(?:or)?d|api[_-]?key|authorization|private[_-]?key/i';
    private const MASK = '••••••';
    private const MAX_DEPTH = 32;
    private const MAX_BYTES = 100_000;

    /**
     * Read-only display of any value (array, JSON string, scalar, object) as escaped, pretty-printed JSON with sensitive
     * keys masked. Never throws: invalid UTF-8 is substituted, deep nesting is cut, very large output is truncated.
     */
    public static function toPrettyHtml(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true, self::MAX_DEPTH + 1);
            $value = is_array($decoded) ? $decoded : $value;
        }

        if (is_string($value)) {
            $text = mb_scrub($value, 'UTF-8');
        } else {
            $text = json_encode(
                self::mask(self::normalize($value), 0),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            $text = false === $text ? '(value cannot be displayed)' : $text;
        }

        if (strlen($text) > self::MAX_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_BYTES, 'UTF-8').sprintf("\n… (truncated, %d bytes in total)", strlen($text));
        }

        return sprintf(
            '<pre class="admin-json">%s</pre>',
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * The same masking as the display, for data that is stored (audit log details).
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function maskSecrets(array $value): array
    {
        return (array) self::mask($value, 0);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return is_resource($value) ? '(resource)' : $value;
    }

    private static function mask(mixed $value, int $depth): mixed
    {
        $value = self::normalize($value);
        if (!is_array($value)) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '…';
        }

        foreach ($value as $key => $item) {
            $value[$key] = is_string($key) && 1 === preg_match(self::SENSITIVE_KEY, $key) && null !== $item && '' !== $item
                ? self::MASK
                : self::mask($item, $depth + 1);
        }

        return $value;
    }

    /**
     * @param array<string, string>|null $value
     */
    public static function localizedText(?array $value, string $locale): string
    {
        if (!is_array($value)) {
            return '';
        }

        return (string) ($value[$locale] ?? $value['fa'] ?? $value['en'] ?? '');
    }

    public static function badges(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $badges = [];
        foreach ($value as $item) {
            $badges[] = sprintf(
                '<span class="badge badge-secondary">%s</span>',
                htmlspecialchars((string) $item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return implode(' ', $badges);
    }
}
