<?php

declare(strict_types=1);

/*
 * Test helper: an admin's "retry provisioning" of one payment in its own PHP process (own DB connection), so a test
 * can queue it on an order lock another settlement holds. Usage: php retry-provisioning.php <paymentId>
 * Prints the settlement outcome.
 */

use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$payment = $entityManager->find(Payment::class, (int) ($argv[1] ?? 0));

echo $container->get(PaymentSettlementService::class)->retryProvisioning($payment, 1, null, 'concurrency_test_admin')->outcome->value;
