<?php

declare(strict_types=1);

/*
 * Test helper: customer-cancels one order in its own PHP process (own DB connection), so tests can
 * race a cancel against a settlement. Usage: php cancel-order.php <orderId>
 * Prints the settlement outcome.
 */

use App\Entity\Order;
use App\Payment\Application\Settlement\PaymentSettlementService;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

// Load the order BEFORE the race, like the bot does when the button is pressed: stale state.
$order = $entityManager->find(Order::class, (int) ($argv[1] ?? 0));
$delay = (int) ($argv[2] ?? 0);
if ($delay > 0) {
    usleep($delay * 1000);
}

echo $container->get(PaymentSettlementService::class)->cancelByCustomer($order)->outcome->value;
