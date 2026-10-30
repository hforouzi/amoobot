<?php

declare(strict_types=1);

/*
 * Test helper: a reseller makes a claim link (ClaimLinks::createForReseller) in its own PHP process, to race another.
 * Usage: php claim-create.php <resellerUserId> <serviceId> [startAt (bin/start-at.php)]. Prints "created:<claimId>" or the
 * ClaimException code.
 */

use App\Entity\User;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Domain\ClaimException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
waitForStartAt((string) ($argv[3] ?? '0'));
$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such user');
try {
    echo 'created:'.$container->get(ClaimLinks::class)->createForReseller($user, (int) ($argv[2] ?? 0))->claim->getId();
} catch (ClaimException $e) {
    echo $e->error;
}
