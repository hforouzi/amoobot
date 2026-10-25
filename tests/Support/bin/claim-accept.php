<?php

declare(strict_types=1);

/*
 * Test helper: one claim (ClaimService::claim) in its own PHP process, to race another claim, a revoke, the expiry
 * sweep or a renewal. Usage: php claim-accept.php <claimId> <ticket> <telegramId> [startAtUnixMicrotime]
 * Prints the outcome (claimed, already_yours) or the ClaimException code.
 */

use App\Entity\TelegramAccount;
use App\Reseller\Application\ClaimService;
use App\Reseller\Domain\ClaimException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[4] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$telegramId = (string) ($argv[3] ?? '');
$user = $entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId])?->getUser() ?? throw new RuntimeException('no such user');
try {
    [$outcome] = $container->get(ClaimService::class)->claim((int) ($argv[1] ?? 0), (string) ($argv[2] ?? ''), $user, $telegramId);
    echo $outcome;
} catch (ClaimException $e) {
    echo $e->error;
}
