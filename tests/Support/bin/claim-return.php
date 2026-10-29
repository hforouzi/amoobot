<?php

declare(strict_types=1);

/*
 * Test helper: an admin's "Return to reseller" (ServiceOwnership::returnToReseller) in its own PHP process, to race a
 * claim or a reset. Usage: php claim-return.php <serviceId> [startAtUnixMicrotime]
 * Prints "returned" or the ClaimException code.
 */

use App\Entity\VpnService;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Security\AdminActor;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[2] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$service = $entityManager->find(VpnService::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such service');
try {
    $container->get(ServiceOwnership::class)->returnToReseller($service, 'wrong person', AdminActor::environmentBotAdmin('990001'));
    echo 'returned';
} catch (ClaimException $e) {
    echo $e->error;
}
