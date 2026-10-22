<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure;

use App\Entity\WalletTransaction;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * The ledger is append-only: a WalletTransaction is never updated or removed through Doctrine. Corrections are new
 * rows (WalletService::reverse() / an ADJUSTMENT).
 */
#[AsEntityListener(event: Events::preUpdate, method: 'refuse', entity: WalletTransaction::class)]
#[AsEntityListener(event: Events::preRemove, method: 'refuse', entity: WalletTransaction::class)]
final class WalletLedgerGuard
{
    public function refuse(WalletTransaction $transaction): void
    {
        throw new \LogicException(sprintf('Wallet ledger rows are append-only; transaction #%d cannot be changed or deleted. Write a reversal or an adjustment instead.', $transaction->getId() ?? 0));
    }
}
