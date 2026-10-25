<?php

declare(strict_types=1);

/*
 * Test helper: a reseller renews a service he sold (quote, then pay) in its own PHP process, to race a claim.
 * Usage: php reseller-renew.php <resellerUserId> <serviceId> [startAtUnixMicrotime]. Prints the settlement outcome or the
 * error code.
 */

use App\Entity\User;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerException;
use App\Wallet\Domain\WalletException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[3] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such user');
$serviceId = (int) ($argv[2] ?? 0);
$checkout = $container->get(ResellerCheckout::class);
try {
    [, , , $confirmation] = $checkout->quoteRenewal($user, $serviceId);
    echo $checkout->renew($user, $serviceId, $confirmation)->outcome->value;
} catch (ResellerException|WalletException $e) {
    echo $e->error;
}
