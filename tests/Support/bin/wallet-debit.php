<?php

declare(strict_types=1);

/*
 * Test helper: one wallet debit in its own PHP process (own DB connection), so a test can run two debits truly in
 * parallel. The debit runs inside an outer transaction that is held open for <holdMs> after the debit, so the wallet
 * row lock is held while the other process tries. Usage: php wallet-debit.php <userId> <amount> <idempotencyKey> <holdMs>
 * Prints "ok" or the wallet error code.
 */

use App\Entity\User;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletTransactionType;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0));
$amount = (int) ($argv[2] ?? 0);
$key = (string) ($argv[3] ?? '');
$holdMs = (int) ($argv[4] ?? 0);

$connection = $entityManager->getConnection();
$connection->beginTransaction();
try {
    $container->get(WalletService::class)->debit($user, $amount, WalletTransactionType::PURCHASE, $key, WalletActor::system());
    usleep($holdMs * 1000);
    $connection->commit();
    echo 'ok';
} catch (WalletException $e) {
    $connection->rollBack();
    echo $e->error;
}
