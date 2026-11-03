<?php

declare(strict_types=1);

/*
 * Test helper: an admin deletes a trial plan (TrialPlanAdminService::delete, as the web panel and the Mini App do) in
 * its own PHP process, to race a claim. Usage: php trial-plan-delete.php <trialPlanId> [startAt (bin/start-at.php)].
 * Prints the outcome and the counts it saw: "<outcome>:<orders>:<services>".
 */

use App\Entity\TrialPlan;
use App\Shop\Application\Catalog\TrialPlanAdminService;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
$trialPlan = $entityManager->find(TrialPlan::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such trial plan');
waitForStartAt((string) ($argv[2] ?? '0'));
$deletion = $container->get(TrialPlanAdminService::class)->delete($trialPlan);
echo $deletion->outcome.':'.$deletion->orders.':'.$deletion->services;
