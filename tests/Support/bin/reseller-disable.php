<?php

declare(strict_types=1);

/*
 * Test helper: an admin disables a reseller (ResellerAdministration) in its own PHP process, to race a purchase.
 * Usage: php reseller-disable.php <resellerId>. Prints "disabled".
 */

use App\Entity\Reseller;
use App\Reseller\Application\ResellerAdministration;
use App\Security\AdminActor;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

usleep(150000);
$reseller = $entityManager->find(Reseller::class, (int) ($argv[1] ?? 0));
$container->get(ResellerAdministration::class)->disable($reseller, AdminActor::environmentBotAdmin('990001'));
echo 'disabled';
