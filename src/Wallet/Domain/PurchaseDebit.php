<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/**
 * Where the wallet debit of a payment of method "wallet" stands (WalletService::purchaseDebit()).
 */
enum PurchaseDebit: string
{
    /** Debited and not given back: the payment may be confirmed. */
    case HELD = 'held';
    /** Debited, then given back (a REVERSAL exists): confirming would deliver the order without payment. */
    case REVERSED = 'reversed';
    /** Never debited. */
    case MISSING = 'missing';
}
