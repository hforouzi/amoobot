<?php

declare(strict_types=1);

/*
 * Test helper: one reseller purchase of a new service (ResellerCheckout) in its own PHP process (own DB connection),
 * so a test can race two of them. Usage: php reseller-buy.php <userId> <planId>
 * Prints the settlement outcome, or the reseller / wallet error code when the purchase was refused.
 */

use App\Entity\User;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerException;
use App\Wallet\Domain\WalletException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0));
try {
    echo $container->get(ResellerCheckout::class)->buyNewService($user, (int) ($argv[2] ?? 0))->outcome->value;
} catch (ResellerException|WalletException $e) {
    echo $e->error;
}
