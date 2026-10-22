<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/**
 * The ledger idempotency keys of the payment flows, one place so that every writer of a movement uses the same key
 * (and a retry therefore finds the first row instead of writing a second one).
 */
final class WalletKeys
{
    /** The credit of a confirmed WALLET_TOPUP payment. */
    public static function topUp(int $paymentId): string
    {
        return 'topup:payment:'.$paymentId;
    }

    /** The debit of an order paid with payment method "wallet". */
    public static function purchase(int $paymentId): string
    {
        return 'purchase:payment:'.$paymentId;
    }

    /** Giving that debit back when the wallet payment is rejected / cancelled / expired. */
    public static function purchaseReversal(int $paymentId): string
    {
        return 'reversal:payment:'.$paymentId;
    }
}
