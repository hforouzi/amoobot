<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

/**
 * A wallet write that was refused; nothing was written. $error is stable (pages, tests and translations read it:
 * wallet.error.<error>).
 */
class WalletException extends \RuntimeException
{
    public function __construct(public readonly string $error, string $message = '')
    {
        parent::__construct('' !== $message ? $message : $error);
    }
}
