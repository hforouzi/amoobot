<?php

declare(strict_types=1);

/*
 * Test helper: the owner's "Reset my connection links" (ServiceOwnership::resetConnectionLinks) in its own PHP process,
 * to race a second delivery of the same button or a return to the reseller.
 * Usage: php claim-reset.php <telegramId> <serviceId> <nonce> [startAtUnixMicrotime]
 * Prints the outcome (reset, already_reset) or the ClaimException code.
 */

use App\Entity\TelegramAccount;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[4] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$user = $entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => (string) ($argv[1] ?? '')])?->getUser() ?? throw new RuntimeException('no such user');
try {
    [$outcome] = $container->get(ServiceOwnership::class)->resetConnectionLinks($user, (int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''));
    echo $outcome;
} catch (ClaimException $e) {
    echo $e->error;
}
