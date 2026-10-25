<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * The secret of a claim link (docs/design/reseller-and-claim.md, 1.5): 32 random bytes (256 bit), base64url without
 * padding, 43 characters from [A-Za-z0-9_-]. Only its SHA-256 (hex) is stored (service_claim.token_hash); the token is
 * shown to its creator once and never stored, logged or audited. A plain hash is enough: there is no dictionary to try
 * against 256 random bits, and lookup is by the indexed hash, so the token itself has no timing oracle.
 *
 * The deep-link payload is "claim_" + token: 49 characters, inside Telegram's 64-character limit and alphabet for
 * start / startapp.
 */
final class ClaimToken
{
    public const PREFIX = 'claim';
    public const LENGTH = 43;
    /** Exactly a token: no trimming, no suffix (/D: "$" is the very end, a trailing "\n" does not match). */
    private const PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return 1 === preg_match(self::PATTERN, $token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The /start and startapp payload of this token. */
    public static function payload(string $token): string
    {
        return self::PREFIX.'_'.$token;
    }

    /**
     * Text with every claim payload masked ("claim_" followed by 20+ token characters becomes "claim_[redacted]"), for
     * anything that is stored or logged: the bot message log keeps incoming /start texts and the links sent to
     * resellers.
     */
    public static function redact(string $text): string
    {
        if (!str_contains($text, self::PREFIX.'_')) {
            return $text;
        }

        return (string) preg_replace('/'.self::PREFIX.'_[A-Za-z0-9_-]{20,}/', self::PREFIX.'_[redacted]', $text);
    }
}
