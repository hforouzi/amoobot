<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use Psr\Clock\ClockInterface;

/**
 * The step between opening a claim link and confirming it. Opening it (the preview) proves the person holds the token;
 * the confirm button (bot callback data, Mini App form) then carries the claim id and a ticket instead of the token, so
 * the token is never put into callback data, a URL or a log line.
 *
 * A ticket is bound to one claim and one Telegram user: issue time (base36, 7 characters) + 16 characters of an HMAC
 * (APP_SECRET) over the claim id, the Telegram user id, the claim's token hash and the issue time. Nobody else can use
 * it, a forged or altered one is refused, and it expires after TTL_SECONDS. Nothing is stored.
 */
class ClaimTickets
{
    public const TTL_SECONDS = 1800;
    // /D: "$" is the very end, so "...\n" or any suffix is refused (nothing is trimmed).
    private const PATTERN = '/^([0-9a-z]{7})([A-Za-z0-9_-]{16})$/D';

    public function __construct(
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    public function issue(int $claimId, string $telegramUserId, string $tokenHash): string
    {
        $time = str_pad(base_convert((string) $this->clock->now()->getTimestamp(), 10, 36), 7, '0', STR_PAD_LEFT);

        return $time.$this->mac($claimId, $telegramUserId, $tokenHash, $time);
    }

    /** Whether this ticket was issued for this claim and this Telegram user, and is not expired. */
    public function isValid(string $ticket, int $claimId, string $telegramUserId, string $tokenHash): bool
    {
        if (1 !== preg_match(self::PATTERN, $ticket, $m) || !hash_equals($this->mac($claimId, $telegramUserId, $tokenHash, $m[1]), $m[2])) {
            return false;
        }
        $age = $this->clock->now()->getTimestamp() - (int) base_convert($m[1], 36, 10);

        return $age >= -60 && $age <= self::TTL_SECONDS;
    }

    private function mac(int $claimId, string $telegramUserId, string $tokenHash, string $time): string
    {
        $data = implode('|', ['claim-ticket-v1', $claimId, $telegramUserId, $tokenHash, $time]);

        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->appSecret, true)), '+/', '-_'), '='), 0, 16);
    }
}
