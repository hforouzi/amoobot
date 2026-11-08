<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Entity\Payment;

/**
 * What the customer must pay at the gateway, in Toman, when the gateway asks for more than the payment's own amount
 * (HooshPay: a few Toman that make the amount unique, plus the buyer's share of the fee). A gateway reports it as
 * rawResponse[KEY] of a successful createPayment(); PaymentStarter stores that as the request payload.
 *
 * Display only: the bot and the Mini App show it next to the payment link. Settlement and wallet credit never read it;
 * they use the payment's own expected amount.
 */
final class GatewayPayableAmount
{
    public const KEY = 'customer_payable_amount';

    /** The stored amount when it is a positive integer; else null (the gateway reported none). */
    public static function of(Payment $payment): ?int
    {
        $payload = $payment->getRequestPayload();
        $value = is_array($payload) ? ($payload[self::KEY] ?? null) : null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * The bot's line under an online payment link ("\nAmount to pay at the gateway: 300,017 Toman"), or "" when the
     * gateway reported no amount.
     */
    public static function botLine(Payment $payment): string
    {
        $amount = self::of($payment);

        return null === $amount ? '' : "\n".'💳 مبلغ قابل پرداخت در درگاه: '.number_format($amount).' تومان';
    }
}
