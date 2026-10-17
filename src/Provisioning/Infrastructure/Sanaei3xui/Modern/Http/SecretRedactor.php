<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * Removes credentials from any text that may reach a log, an exception, the admin UI or the console:
 * the exact secret values given, Authorization/Bearer headers, and token/password-shaped key=value pairs.
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    /**
     * @param list<string|null> $secrets exact values to remove (the panel's token, password)
     */
    public static function redact(string $text, array $secrets = [], int $maxLength = 500): string
    {
        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if (strlen($secret) >= 4) {
                $text = str_replace([$secret, rawurlencode($secret)], self::MASK, $text);
            }
        }
        $text = preg_replace('/(Authorization\s*[:=]\s*)("?)[^\s",}]+(\s+[^\s",}]+)?/i', '$1$2'.self::MASK, $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::MASK, $text) ?? $text;
        $text = preg_replace('/([?&](?:api[_-]?token|access[_-]?token|token|password|passwd|session)=)[^&#\s]*/i', '$1'.self::MASK, $text) ?? $text;
        $text = preg_replace('/("?(?:password|passwd|token|api[_-]?token|cookie|secret|authorization)"?\s*[:=]\s*)"[^"]*"/i', '$1"'.self::MASK.'"', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, $maxLength);
    }

    /** "abcd…wxyz" for long values, "set" for short ones, "" when empty: for showing that a token is configured. */
    public static function mask(?string $secret): string
    {
        $secret = trim((string) $secret);
        if ('' === $secret) {
            return '';
        }

        return strlen($secret) >= 16 ? substr($secret, 0, 4).'…'.substr($secret, -4) : '••••';
    }
}
