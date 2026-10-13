<?php

declare(strict_types=1);

/*
 * Test helper: customer-cancels one order in its own PHP process (own DB connection), so tests can
 * race a cancel against a settlement. Usage: php cancel-order.php <orderId>
 * Prints the settlement outcome.
 */

use App\Entity\Order;
use App\Kernel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$entityManager = $container->get('doctrine')->getManager();

// Load the order BEFORE the race, like the bot does when the button is pressed: stale state.
$order = $entityManager->find(Order::class, (int) ($argv[1] ?? 0));
$delay = (int) ($argv[2] ?? 0);
if ($delay > 0) {
    usleep($delay * 1000);
}

echo $container->get(PaymentSettlementService::class)->cancelByCustomer($order)->outcome->value;
