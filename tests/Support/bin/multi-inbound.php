<?php

declare(strict_types=1);

/*
 * Test helper: one multi-inbound step in its own PHP process (own DB connection), against a fake 3x-ui whose state is
 * shared with the other processes through <stateFile> (Fake3xuiPanel::$stateFile), so a test can race two of them.
 * Every request to the panel waits <delayMs> first (outside the panel's lock), so the two overlap.
 *
 * Usage: php multi-inbound.php settle <paymentId> <stateFile> <delayMs>        prints the settlement outcome
 *        php multi-inbound.php retry-attach <serviceId> <stateFile> <delayMs>  prints the retry outcome
 */

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\MockHttpResponder;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$mode, $id, $stateFile, $delayMs] = array_slice($argv ?? [], 1) + array_fill(0, 4, '');

$fake = new Fake3xuiPanel();
$fake->stateFile = $stateFile;
$fake->delays = ['(GET|POST) .*' => (int) $delayMs];
$container->get(MockHttpResponder::class)->on('panel.example.test', $fake);

if ('settle' === $mode) {
    $payment = $entityManager->find(Payment::class, (int) $id) ?? throw new RuntimeException('no such payment');
    echo $container->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'concurrency_test')->outcome->value;

    return;
}

$service = $entityManager->find(VpnService::class, (int) $id) ?? throw new RuntimeException('no such service');
echo $container->get(ServiceInboundAttacher::class)->retry($service, 'concurrency_test')->outcome;
