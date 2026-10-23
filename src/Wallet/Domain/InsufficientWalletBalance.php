<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/** balance + credit limit would go below zero. */
final class InsufficientWalletBalance extends WalletException
{
    public function __construct(int $balance, int $creditLimit, int $amount)
    {
        parent::__construct('wallet_insufficient', sprintf('Wallet balance %d (credit limit %d) does not cover %d.', $balance, $creditLimit, $amount));
    }
}
