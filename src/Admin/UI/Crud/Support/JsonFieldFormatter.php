<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud\Support;

final class JsonFieldFormatter
{
    public static function format(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (!is_array($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return false === $encoded ? '' : $encoded;
    }
}

