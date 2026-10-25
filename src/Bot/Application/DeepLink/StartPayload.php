<?php

declare(strict_types=1);

namespace App\Bot\Application\DeepLink;

/**
 * A deep-link payload (docs/design/reseller-and-claim.md, 3.2): what follows "/start " in the bot or arrives as the Mini
 * App's start_param, e.g. "claim_<token>" (later "ref_<code>"). "<prefix>_<value>": the prefix is lower-case letters,
 * the value 1..58 characters of Telegram's start alphabet [A-Za-z0-9_-]; at most 64 characters in all.
 *
 * Parsed exactly as received: nothing is trimmed, and anything else (spaces, a newline, other characters, too long) is
 * not a payload (/D: "$" is the very end, so a trailing "\n" does not match).
 */
final class StartPayload
{
    private const PATTERN = '/^([a-z]{1,16})_([A-Za-z0-9_-]{1,58})$/D';

    private function __construct(
        public readonly string $prefix,
        #[\SensitiveParameter]
        public readonly string $value,
    ) {
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > 64 || 1 !== preg_match(self::PATTERN, $raw, $m)) {
            return null;
        }

        return new self($m[1], $m[2]);
    }

    /**
     * The payload of a bot message: "/start <payload>" exactly (one space; Telegram's "/start@bot <payload>" too).
     * Null for a plain "/start" and for anything that is not a well-formed payload.
     */
    public static function fromStartCommand(string $text): ?self
    {
        if (1 !== preg_match('/^\/start(?:@[A-Za-z0-9_]{5,32})? (\S+)$/D', $text, $m)) {
            return null;
        }

        return self::parse($m[1]);
    }

    /** Whether a bot message is a /start command (with or without a payload, well-formed or not). */
    public static function isStartCommand(string $text): bool
    {
        return 1 === preg_match('/^\/start(?:@[A-Za-z0-9_]{5,32})?(?: .*)?$/sD', $text);
    }
}
