<?php

declare(strict_types=1);

/*
 * Test helper: settles one payment in its own PHP process (own DB connection), so tests can run
 * two settlements truly in parallel. Usage: php settle-payment.php <paymentId> <expectedAmountToman>
 * Prints the settlement outcome.
 */

use App\Kernel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Entity\Payment;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

$paymentId = (int) ($argv[1] ?? 0);
$amount = (string) ($argv[2] ?? '0');

$payment = $container->get('doctrine')->getManager()->find(Payment::class, $paymentId);
$verification = new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-'.$paymentId, amount: $amount, currency: 'IRT');

$result = $container->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'concurrency_test');

echo $result->outcome->value;
