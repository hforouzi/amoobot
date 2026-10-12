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
$entityManager = $container->get('doctrine')->getManager();

// auto_generate_proxy_classes rewrites proxy files whenever a process first needs them. Two processes
// doing that at once collide on Windows ("rename(): Access is denied"), so load every proxy class
// under a file lock before the actual (parallel) work starts.
$lock = fopen(sys_get_temp_dir().'/amoobot-test-proxies.lock', 'c');
flock($lock, LOCK_EX);
foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
    if (!$metadata->isMappedSuperclass && !$metadata->isEmbeddedClass && 1 === count($metadata->getIdentifierFieldNames())) {
        $entityManager->getProxyFactory()->getProxy($metadata->getName(), [$metadata->getIdentifierFieldNames()[0] => 0]);
    }
}
flock($lock, LOCK_UN);

$payment = $entityManager->find(Payment::class, $paymentId);
$verification = new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-'.$paymentId, amount: $amount, currency: 'IRT');

$result = $container->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'concurrency_test');

echo $result->outcome->value;
