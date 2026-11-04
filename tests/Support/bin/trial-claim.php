<?php

declare(strict_types=1);

/*
 * Test helper: a customer claims a trial (TrialAccountService::claim) in its own PHP process, to race a delete of the
 * trial plan. Usage: php trial-claim.php <telegramAccountId> <trialPlanId> [startAt (bin/start-at.php)]. The trial plan
 * is loaded before the start (as a request would have it). Prints the claim result's status.
 */

use App\Entity\TelegramAccount;
use App\Entity\TrialPlan;
use App\Shop\Application\TrialAccountService;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

require_once __DIR__.'/start-at.php';
$account = $entityManager->find(TelegramAccount::class, (int) ($argv[1] ?? 0)) ?? throw new RuntimeException('no such account');
$trialPlan = $entityManager->find(TrialPlan::class, (int) ($argv[2] ?? 0)) ?? throw new RuntimeException('no such trial plan');
waitForStartAt((string) ($argv[3] ?? '0'));
echo $container->get(TrialAccountService::class)->claim($account, $trialPlan)->status;
