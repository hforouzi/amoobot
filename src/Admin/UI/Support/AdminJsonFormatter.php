<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

final class AdminJsonFormatter
{
    public static function toPrettyHtml(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (JSON_ERROR_NONE === json_last_error()) {
                $value = $decoded;
            }
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            $json = (string) $value;
        }

        return sprintf(
            '<pre class="admin-json">%s</pre>',
            htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
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
