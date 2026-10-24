<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use Psr\Clock\ClockInterface;

/**
 * One-time confirmations of reseller purchases (docs/design/reseller-and-claim.md, 2.3; review item B1/B2).
 *
 * The confirmation step issues a nonce for exactly what was shown: this reseller, this kind of purchase (new service,
 * renewal, extra traffic), this plan / service, this many GB and days (0 when not chosen) and this amount. The nonce carries its issue time and an
 * HMAC (APP_SECRET) over all of that, so nothing is stored until the purchase: a nonce for another plan, service, GB or
 * amount, or a forged one, is refused (confirmation_invalid); an old one expires after TTL seconds
 * (confirmation_expired). The purchase stores the nonce on its order (unique per reseller), which makes a second
 * delivery of the same confirmation (double tap, Telegram redelivery) a no-op: ResellerCheckout finds the first order.
 *
 * Format: issue time (base36, 7 characters) + 6 random hex characters + 10 characters of the HMAC (base64url),
 * 23 characters from [0-9a-zA-Z_-] (no ':'), so it fits Telegram's 64-byte callback data with the ids and amount.
 */
class ResellerConfirmations
{
    public const KIND_NEW = 'new';
    public const KIND_RENEWAL = 'renewal';
    public const KIND_TRAFFIC = 'traffic';
    public const TTL_SECONDS = 600;

    // /D: $ is the very end (without it "…\n" would match: another nonce for the unique key, signature still valid).
    private const PATTERN = '/^([0-9a-z]{7})([0-9a-f]{6})([A-Za-z0-9_-]{10})$/D';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $appSecret,
    ) {
    }

    public function issue(Reseller $reseller, string $kind, int $targetId, int $trafficGb, int $durationDays, int $amount): ResellerConfirmation
    {
        $time = str_pad(base_convert((string) $this->clock->now()->getTimestamp(), 10, 36), 7, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(3));

        return new ResellerConfirmation($amount, $time.$random.$this->mac($reseller, $kind, $targetId, $trafficGb, $durationDays, $amount, $time.$random));
    }

    /**
     * The nonce was issued by us for exactly this purchase (signature only; the expiry is checked separately, so a
     * replay of a used nonce can still be recognised after it expired).
     *
     * @throws ResellerException confirmation_invalid
     */
    public function assertAuthentic(Reseller $reseller, string $kind, int $targetId, int $trafficGb, int $durationDays, ResellerConfirmation $confirmation): void
    {
        if (1 !== preg_match(self::PATTERN, $confirmation->nonce, $m)
            || !hash_equals($this->mac($reseller, $kind, $targetId, $trafficGb, $durationDays, $confirmation->amount, $m[1].$m[2]), $m[3])) {
            throw new ResellerException('confirmation_invalid');
        }
    }

    /**
     * @throws ResellerException confirmation_expired
     */
    public function assertFresh(ResellerConfirmation $confirmation): void
    {
        $issued = 1 === preg_match(self::PATTERN, $confirmation->nonce, $m) ? (int) base_convert($m[1], 36, 10) : 0;
        $age = $this->clock->now()->getTimestamp() - $issued;
        if ($age < -60 || $age > self::TTL_SECONDS) {
            throw new ResellerException('confirmation_expired');
        }
    }

    private function mac(Reseller $reseller, string $kind, int $targetId, int $trafficGb, int $durationDays, int $amount, string $timeAndRandom): string
    {
        $data = implode('|', ['reseller-confirmation-v1', (int) $reseller->getId(), $kind, $targetId, $trafficGb, $durationDays, $amount, $timeAndRandom]);

        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->appSecret, true)), '+/', '-_'), '='), 0, 10);
    }
}
