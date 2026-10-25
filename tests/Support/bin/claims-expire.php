<?php

declare(strict_types=1);

/*
 * Test helper: the expiry sweep (ClaimMaintenance::expire) in its own PHP process, to race a claim.
 * Usage: php claims-expire.php [startAtUnixMicrotime]. Prints the number of links expired.
 */

use App\Reseller\Application\ClaimMaintenance;

[$container] = require __DIR__.'/boot-test-kernel.php';

$wait = (float) ($argv[1] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
echo (string) $container->get(ClaimMaintenance::class)->expire(100);
