<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/**
 * What a ledger row is. The sign of WalletTransaction::$amount says the direction; the type says why.
 */
final class WalletTransactionType
{
    /** Money paid in through a payment gateway (WALLET_TOPUP order). */
    public const TOPUP = 'topup';
    /** An order paid from the wallet (payment method "wallet"). */
    public const PURCHASE = 'purchase';
    /** Money returned to the wallet outside a reversal (reserved for later flows). */
    public const REFUND = 'refund';
    /** A manual correction by an admin, credit or debit, always with a reason. */
    public const ADJUSTMENT = 'adjustment';
    /** A reward (referrals, later). */
    public const REWARD = 'reward';
    /** Undoes exactly one earlier row (opposite amount, linked by reversalOf). Only WalletService::reverse() writes it. */
    public const REVERSAL = 'reversal';

    public const ALL = [self::TOPUP, self::PURCHASE, self::REFUND, self::ADJUSTMENT, self::REWARD, self::REVERSAL];

    /** Types credit()/debit() accept (a reversal goes through reverse()). */
    public const DIRECT = [self::TOPUP, self::PURCHASE, self::REFUND, self::ADJUSTMENT, self::REWARD];
}
