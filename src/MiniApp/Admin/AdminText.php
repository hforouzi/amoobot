<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;

/**
 * Text and numbers crossing the Mini App admin boundary. safe(): text from a gateway, a panel or the settlement
 * (review reasons, provisioning errors) with credentials removed (SecretRedactor) and every URL / IP address replaced,
 * so no panel or gateway address reaches the app. integer(): a number an admin typed, Persian / Arabic digits accepted.
 */
final class AdminText
{
    public const URL_MASK = '[url]';
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function safe(?string $text, int $maxLength = 300): ?string
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }
        $text = SecretRedactor::redact($text, [], 2000);
        $text = preg_replace('#\b[a-z][a-z0-9+.-]*://[^\s"\'<>]+#i', self::URL_MASK, $text) ?? $text;
        $text = preg_replace('#\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b#', self::URL_MASK, $text) ?? $text;

        return mb_substr($text, 0, $maxLength);
    }

    public static function latinDigits(string $value): string
    {
        return strtr($value, self::DIGITS);
    }

    /** A whole number (at most 9 digits), else null. */
    public static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim(self::latinDigits($value));

        return 1 === preg_match('/^\d{1,9}$/', $value) ? (int) $value : null;
    }
}
