<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Entity\Payment;

/**
 * Builds the customer-facing URLs that carry a payment's unguessable return token.
 *
 * Gateways (core and plugins) call this in createPayment():
 *   - return page (where the provider sends the customer back): returnUrl() / cancelUrl()
 *   - browser callbacks that the core processes first: withToken() adds ?rt=<token>; after the
 *     callback is processed the customer is redirected to the return page.
 *
 * The token only identifies the payment for display. Callbacks/webhooks are still matched by the
 * gateway's own stored references and verified with the provider.
 */
final class PaymentReturnUrl
{
    public const QUERY_PARAMETER = 'rt';
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{32,64}';

    public static function path(Payment $payment): string
    {
        return '/payment/return/'.rawurlencode(self::token($payment));
    }

    public static function returnUrl(string $baseUrl, Payment $payment): string
    {
        return rtrim(trim($baseUrl), '/').self::path($payment);
    }

    public static function cancelUrl(string $baseUrl, Payment $payment): string
    {
        return self::returnUrl($baseUrl, $payment).'/cancel';
    }

    public static function withToken(string $url, Payment $payment): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::QUERY_PARAMETER.'='.rawurlencode(self::token($payment));
    }

    private static function token(Payment $payment): string
    {
        // Payments created before the token existed get one the first time a URL is built for them.
        return $payment->ensureReturnToken();
    }
}
