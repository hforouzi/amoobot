<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/** What a ledger row's referenceId points at. */
final class WalletReferenceType
{
    public const PAYMENT = 'payment';
    public const ORDER = 'order';
    public const ADMIN = 'admin';
    public const REFERRAL = 'referral';
}
