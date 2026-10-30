<?php

declare(strict_types=1);

/*
 * Test helper: the expiry sweep (ClaimMaintenance::expire) in its own PHP process, to race a claim.
 * Usage: php claims-expire.php [startAt (bin/start-at.php)]. Prints the number of links expired.
 */

use App\Reseller\Application\ClaimMaintenance;

[$container] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
waitForStartAt((string) ($argv[1] ?? '0'));
echo (string) $container->get(ClaimMaintenance::class)->expire(100);
