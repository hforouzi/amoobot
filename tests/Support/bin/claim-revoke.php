<?php

declare(strict_types=1);

/*
 * Test helper: an admin revokes a claim link (ClaimLinks::revokeByAdmin) in its own PHP process, to race a claim.
 * Usage: php claim-revoke.php <claimId> [startAt (bin/start-at.php)]. Prints "revoked" or "not_open".
 */

use App\Reseller\Application\ClaimLinks;
use App\Security\AdminActor;

[$container] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
waitForStartAt((string) ($argv[2] ?? '0'));
echo $container->get(ClaimLinks::class)->revokeByAdmin((int) ($argv[1] ?? 0), AdminActor::environmentBotAdmin('990001')) ? 'revoked' : 'not_open';
