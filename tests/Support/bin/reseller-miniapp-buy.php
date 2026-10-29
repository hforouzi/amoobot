<?php

declare(strict_types=1);

/*
 * Test helper: one reseller purchase call of the Mini App (ResellerPurchases, what MiniAppResellerController::buy runs:
 * Idempotency-Key, the confirmation token, ResellerCheckout) in its own PHP process (own DB connection), so a test can
 * race two of them. Usage: php reseller-miniapp-buy.php <telegramId> <planId> <idempotencyKey> <confirmToken>.
 * Prints [status, answer] as JSON.
 */

use App\Entity\MiniAppSession;
use App\Entity\TelegramAccount;
use App\MiniApp\Reseller\ResellerPurchases;
use App\MiniApp\Security\MiniAppUser;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$telegramId, $planId, $key, $token] = [(string) ($argv[1] ?? ''), (int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')];
$account = $entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId]) ?? throw new LogicException('no account');
$user = new MiniAppUser($account, new MiniAppSession(str_repeat('0', 64), $telegramId, str_repeat('0', 64), new DateTimeImmutable(), new DateTimeImmutable('+1 hour')), null);

echo json_encode($container->get(ResellerPurchases::class)->purchase($user, ResellerPurchases::KIND_NEW, $planId, 0, 0, $key, $token, 'en'), JSON_THROW_ON_ERROR);
