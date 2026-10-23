<?php

declare(strict_types=1);

/*
 * Test helper: one Mini App checkout call in its own PHP process (own DB connection), under CheckoutIdempotency, so a
 * test can run two calls with the same Idempotency-Key truly in parallel. The action waits <holdMs> inside the
 * transaction after creating the order, to make the overlap certain.
 * Usage: php checkout-once.php <telegramId> <planId> <idempotencyKey> <holdMs>. Prints the call's answer as JSON.
 */

use App\Entity\MiniAppSession;
use App\Entity\TelegramAccount;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Security\MiniAppUser;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$telegramId, $planId, $key, $holdMs] = [(string) ($argv[1] ?? ''), (int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''), (int) ($argv[4] ?? 0)];
$account = $entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId]) ?? throw new LogicException('no account');
$user = new MiniAppUser($account, new MiniAppSession(str_repeat('0', 64), $telegramId, str_repeat('0', 64), new DateTimeImmutable(), new DateTimeImmutable('+1 hour')), null);
$checkout = $container->get(MiniAppCheckout::class);

$answer = $container->get(CheckoutIdempotency::class)->once($account->getUser(), $key, 'new_service', static function () use ($checkout, $user, $planId, $holdMs): array {
    $order = $checkout->newService($user, $planId, 'sara', null, null, null);
    usleep($holdMs * 1000);

    return ['redirect' => '/app/view/orders/'.$order->getId().'/pay'];
});

echo json_encode($answer, JSON_THROW_ON_ERROR);
