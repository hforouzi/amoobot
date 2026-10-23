<?php

declare(strict_types=1);

/*
 * Test helper: pays one order from the wallet in its own PHP process (own DB connection), so a test can run two
 * payments truly in parallel. Usage: php wallet-pay.php <orderId>
 * Prints the settlement outcome, or the wallet error code when the payment was refused.
 */

use App\Entity\Order;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Domain\WalletException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$order = $entityManager->find(Order::class, (int) ($argv[1] ?? 0));

try {
    echo $container->get(WalletPayment::class)->pay($order)->outcome->value;
} catch (WalletException $e) {
    echo $e->error;
}
