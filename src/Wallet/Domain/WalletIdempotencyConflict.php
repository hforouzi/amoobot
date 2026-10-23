<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/** The idempotency key was already used for a different movement (other wallet, type or amount). */
final class WalletIdempotencyConflict extends WalletException
{
    public function __construct(string $key)
    {
        parent::__construct('wallet_idempotency_conflict', sprintf('Idempotency key "%s" was already used for another wallet movement.', $key));
    }
}
