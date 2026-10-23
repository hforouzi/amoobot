<?php

declare(strict_types=1);

/*
 * Test helper: one admin wallet adjustment (credit) in its own PHP process (own DB connection), so a test can submit the
 * same adjustment form twice truly in parallel. Usage: php wallet-adjust.php <customerId> <adminUserId> <formKey> <amount>
 * Prints "ok:<ledger row id>" or the error.
 */

use App\Entity\AdminUser;
use App\Entity\User;
use App\Security\AdminActor;
use App\Wallet\Application\WalletAdministration;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$customer = $entityManager->find(User::class, (int) ($argv[1] ?? 0)) ?? throw new LogicException('no customer');
$admin = $entityManager->find(AdminUser::class, (int) ($argv[2] ?? 0)) ?? throw new LogicException('no admin');

try {
    $transaction = $container->get(WalletAdministration::class)->adjust($customer, WalletAdministration::DIRECTION_CREDIT, (string) ($argv[4] ?? '0'), 'Parallel submit test', (string) ($argv[3] ?? ''), AdminActor::fromAdminUser($admin));
    echo 'ok:'.$transaction->getId();
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
