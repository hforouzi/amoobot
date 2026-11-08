<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Entity\Payment;

/**
 * The t.me link a gateway plugin gave for the Mini App (design: tetraminator-gateway decision 6), read from the payment's
 * stored request payload under "mini_app_link", never from the client. The Mini App opens it with openTelegramLink so
 * the customer stays inside Telegram; the bot keeps the payment URL. Checked again here, whatever the plugin did: https,
 * host exactly t.me, no user info, port or fragment, a plain path. Anything else is null and the page opens the payment
 * URL as for every other gateway. Only a plugin gateway's payload is the provider's answer; a built-in gateway stores
 * its own request there, so it never has one.
 */
final class PaymentTelegramLink
{
    public const PAYLOAD_KEY = 'mini_app_link';
    public const MAX_LENGTH = 512;

    /** https://t.me/ then a path and query of plain URL characters, starting with a name (or "+" for an invite). */
    private const PATTERN = '#^https://t\.me/[A-Za-z0-9_+][A-Za-z0-9_+\-.~/?=&%]*\z#';

    public static function of(Payment $payment): ?string
    {
        if (null === $payment->getGateway()?->getPluginCode()) {
            return null;
        }

        return self::valid($payment->getRequestPayload()[self::PAYLOAD_KEY] ?? null);
    }

    public static function valid(mixed $link): ?string
    {
        if (!is_string($link) || strlen($link) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $link) || str_contains(substr($link, strlen('https://')), '//')) {
            return null;
        }
        $parts = parse_url($link);
        if (!is_array($parts) || 't.me' !== ($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return null;
        }

        return $link;
    }
}
