<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Winapay;

/**
 * Winapay web service status codes and how verifyPayment() treats them.
 */
final class WinapayStatus
{
    public const OK = 100;
    public const ALREADY_VERIFIED = 101;

    private const MESSAGES = [
        100 => 'Request accepted.',
        101 => 'Payment was already verified earlier.',
        102 => 'Winapay could not connect to its web service; try again later.',
        -1 => 'The request must be sent through the web service.',
        -2 => 'MerchantID is missing.',
        -3 => 'Amount is missing.',
        -4 => 'CallbackURL is missing.',
        -5 => 'Amount is below the 100 Toman minimum.',
        -6 => 'MerchantID was not found; check merchant_id.',
        -7 => 'The merchant is inactive at Winapay.',
        -8 => 'The merchant account is not a business account.',
        -9 => 'This server\'s IP address is not allowed in the Winapay panel.',
        -10 => 'The callback address does not match the domain registered in the Winapay panel.',
        -11 => 'Winapay could not create the transaction.',
        -12 => 'Invalid Authority.',
        -13 => 'Authority is missing.',
        -14 => 'Transaction not found.',
        -15 => 'The payment was not completed.',
        -16 => 'The amount does not match the transaction amount.',
        -17 => 'This Authority is not registered for this merchant.',
        -18 => 'Paid, but Winapay failed to update the merchant wallet; check the Winapay panel.',
        -19 => 'Transaction status is unknown.',
        -20 => 'Invalid card number.',
        -21 => 'The payer card differs from the allowed card.',
    ];

    /**
     * Definitive answers about the transaction itself: it is not (or not validly) paid. Every other
     * non-100/101 code is a configuration, provider or transient error, and verification may be retried.
     */
    private const NOT_PAID = [-12, -14, -15, -16, -17, -20, -21];

    public static function message(int $code): string
    {
        return sprintf('Winapay: %s (code %d)', self::MESSAGES[$code] ?? 'Unknown status.', $code);
    }

    public static function isNotPaid(int $code): bool
    {
        return in_array($code, self::NOT_PAID, true);
    }

    /**
     * Status as an integer, or null when the response carries no numeric status.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^\s*-?\d{1,6}\s*$/', $value)) {
            return (int) trim($value);
        }

        return null;
    }
}
