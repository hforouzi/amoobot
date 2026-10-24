<?php

declare(strict_types=1);

/*
 * Test helper: one reseller purchase of a new service (ResellerCheckout) in its own PHP process (own DB connection),
 * so a test can race two of them. Usage: php reseller-buy.php <userId> <planId> [<amount> <nonce> [<trafficGb> <days>]]
 * Without a confirmation it quotes and confirms first (as the bot's confirm step does), so two processes are two
 * different taps; with one, both deliver the same tap. Prints the settlement outcome, or the reseller / wallet error
 * code when the purchase was refused.
 */

use App\Entity\User;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Wallet\Domain\WalletException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0));
try {
    $checkout = $container->get(ResellerCheckout::class);
    // A customizable plan's choices (0 = not asked), as the bot's pay button carries them.
    $traffic = isset($argv[5]) && '0' !== $argv[5] ? (int) $argv[5] : null;
    $days = isset($argv[6]) && '0' !== $argv[6] ? (int) $argv[6] : null;
    $confirmation = isset($argv[4]) ? new ResellerConfirmation((int) $argv[3], $argv[4]) : $checkout->quoteNewService($user, (int) ($argv[2] ?? 0), $traffic, $days)[3];
    echo $checkout->buyNewService($user, (int) ($argv[2] ?? 0), $confirmation, $traffic, $days)->outcome->value;
} catch (ResellerException|WalletException $e) {
    echo $e->error;
}
