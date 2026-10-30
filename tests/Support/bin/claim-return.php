<?php

declare(strict_types=1);

/*
 * Test helper: an admin's "Return to reseller" (ServiceOwnership::returnToReseller) in its own PHP process, to race a
 * claim or a reset. Usage: php claim-return.php <serviceId> [startAt (bin/start-at.php)]
 * Prints "returned" or the ClaimException code.
 */

use App\Entity\VpnService;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Security\AdminActor;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
waitForStartAt((string) ($argv[2] ?? '0'));
$service = $entityManager->find(VpnService::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such service');
try {
    $container->get(ServiceOwnership::class)->returnToReseller($service, 'wrong person', AdminActor::environmentBotAdmin('990001'));
    echo 'returned';
} catch (ClaimException $e) {
    echo $e->error;
}
