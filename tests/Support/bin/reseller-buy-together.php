<?php

declare(strict_types=1);

/*
 * Test helper: one reseller purchase (ResellerCheckout, the path of the bot and the Mini App) of any kind, in its own
 * PHP process (own DB connection), started together with others. It quotes first (its own tap), then creates
 * <dir>/ready-<pid> and waits for <dir>/go, so every process reaches the purchase at the same moment.
 * Usage: php reseller-buy-together.php <dir> <userId> <new|renewal|add_traffic> <planId|serviceId> [<trafficGb>]
 * Prints the settlement outcome, or the reseller / wallet error code when the purchase was refused.
 */

use App\Entity\User;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerException;
use App\Wallet\Domain\WalletException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$dir, $userId, $kind, $target, $trafficGb] = array_slice($argv, 1) + array_fill(0, 5, '');
$user = $entityManager->find(User::class, (int) $userId) ?? throw new LogicException('no user');
$checkout = $container->get(ResellerCheckout::class);
$confirmation = match ($kind) {
    'new' => $checkout->quoteNewService($user, (int) $target)[3],
    'renewal' => $checkout->quoteRenewal($user, (int) $target)[3],
    'add_traffic' => $checkout->quoteAddTraffic($user, (int) $target, (int) $trafficGb)[3],
    default => throw new LogicException('unknown kind'),
};

touch($dir.'/ready-'.getmypid());
$until = microtime(true) + 120;
while (!is_file($dir.'/go')) {
    if (microtime(true) > $until) {
        throw new RuntimeException('Not started within 120 s.');
    }
    usleep(500);
}

try {
    $result = match ($kind) {
        'new' => $checkout->buyNewService($user, (int) $target, $confirmation),
        'renewal' => $checkout->renew($user, (int) $target, $confirmation),
        default => $checkout->addTraffic($user, (int) $target, (int) $trafficGb, $confirmation),
    };
    echo $result->outcome->value;
} catch (ResellerException|WalletException $e) {
    echo $e->error;
}
