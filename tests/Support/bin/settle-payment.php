<?php

declare(strict_types=1);

/*
 * Test helper: settles one payment in its own PHP process (own DB connection), so tests can run
 * two settlements truly in parallel. Usage: php settle-payment.php <paymentId> <expectedAmountToman>
 * Prints the settlement outcome.
 */

use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Entity\Payment;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$paymentId = (int) ($argv[1] ?? 0);
$amount = (string) ($argv[2] ?? '0');

$payment = $entityManager->find(Payment::class, $paymentId);
$verification = new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-'.$paymentId, amount: $amount, currency: 'IRT');

$result = $container->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'concurrency_test');

echo $result->outcome->value;
