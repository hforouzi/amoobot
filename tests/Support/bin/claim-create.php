<?php

declare(strict_types=1);

/*
 * Test helper: a reseller makes a claim link (ClaimLinks::createForReseller) in its own PHP process, to race another.
 * Usage: php claim-create.php <resellerUserId> <serviceId> [startAtUnixMicrotime]. Prints "created:<claimId>" or the
 * ClaimException code.
 */

use App\Entity\User;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Domain\ClaimException;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[3] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$user = $entityManager->find(User::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such user');
try {
    echo 'created:'.$container->get(ClaimLinks::class)->createForReseller($user, (int) ($argv[2] ?? 0))->claim->getId();
} catch (ClaimException $e) {
    echo $e->error;
}
