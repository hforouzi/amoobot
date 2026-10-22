<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

final class WalletFrozen extends WalletException
{
    public function __construct()
    {
        parent::__construct('wallet_frozen', 'The wallet is frozen.');
    }
}
