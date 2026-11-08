<?php

declare(strict_types=1);

/*
 * Test helper: one side of a race around a provider invoice, in its own PHP process (own DB connection, own lock wait).
 * The fake provider of InvoicingGatewayPlugin takes TEST_INVOICE_DELAY_MS and counts its calls in TEST_INVOICE_CALLS_FILE.
 * Usage: php invoice-race.php start <orderId> <storePaymentMethodId> <startAt>
 *        php invoice-race.php expire <paymentId> 0 <startAt>
 *        php invoice-race.php settle <paymentId> <amountToman> <startAt>
 *        php invoice-race.php approve <paymentId> 0 <startAt>
 * Prints what happened: "start:ready", "start:refused", or the settlement outcome after "expire:" or "settle:". An admin
 * approval prints "approve:<outcome> <milliseconds from its start to its end>".
 */

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Shop\Application\Checkout\PaymentStarter;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';
require __DIR__.'/start-at.php';

[$action, $id, $argument, $startAt] = [(string) ($argv[1] ?? ''), (int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')];
$settlement = $container->get(PaymentSettlementService::class);
$starter = $container->get(PaymentStarter::class);

if ('start' === $action) {
    $order = $entityManager->find(Order::class, $id) ?? throw new LogicException('no order');
    $method = $entityManager->find(StorePaymentMethod::class, (int) $argument) ?? throw new LogicException('no method');
    waitForStartAt($startAt);
    echo 'start:'.($starter->start($order, $method)->isReady() ? 'ready' : 'refused');

    return;
}

$payment = $entityManager->find(Payment::class, $id) ?? throw new LogicException('no payment');
$started = waitForStartAt($startAt);
if ('approve' === $action) {
    $outcome = $settlement->approveByAdmin($payment, null, null, 'race_admin')->outcome->value;
    printf('approve:%s %d', $outcome, (int) round((microtime(true) - $started) * 1000));

    return;
}
if ('expire' === $action) {
    echo 'expire:'.$settlement->expireUnpaid($payment, 'race_timer')->outcome->value;

    return;
}

$verification = new PaymentVerificationResult(success: true, paid: true, transactionId: $payment->getGatewayTransactionId(), amount: $argument, currency: 'IRT');
echo 'settle:'.$settlement->settle($payment, VerifiedPayment::fromGateway($verification), 'race_webhook')->outcome->value;
