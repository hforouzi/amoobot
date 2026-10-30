<?php

declare(strict_types=1);

/*
 * Test helper: many rounds of one side of a race in one PHP process (one kernel boot): round i starts at
 * t0 + i * gap, for both processes alike. Usage: php claim-rounds.php <claim|return> <roundsJsonFile> <t0 (bin/start-at.php)> <gapSeconds>
 * Each round of the file: {claimId, ticket, telegramId, serviceId, claimDelayMs, returnDelayMs} (the delays shift
 * each side's start within the round, so both orders and real overlaps happen). Prints a JSON list, one outcome per round: the
 * outcome, the ClaimException code, or "UNHANDLED:<class>: <message>" for anything else (the test fails on that).
 */

use App\Entity\TelegramAccount;
use App\Entity\VpnService;
use App\Reseller\Application\ClaimService;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Security\AdminActor;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

$role = (string) ($argv[1] ?? '');
$rounds = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true, 512, JSON_THROW_ON_ERROR);
require_once __DIR__.'/start-at.php';
$t0 = waitForStartAt((string) ($argv[3] ?? '0'));
$gap = (float) ($argv[4] ?? 0.3);
$outcomes = [];
foreach ($rounds as $i => $round) {
    $wait = $t0 + $i * $gap + (int) ($round[$role.'DelayMs'] ?? 0) / 1000 - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }
    try {
        $entityManager->clear();
        if ('claim' === $role) {
            $user = $entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => (string) $round['telegramId']])?->getUser() ?? throw new RuntimeException('no such user');
            [$outcome] = $container->get(ClaimService::class)->claim((int) $round['claimId'], (string) $round['ticket'], $user, (string) $round['telegramId']);
            $outcomes[] = $outcome;
        } else {
            $service = $entityManager->find(VpnService::class, (int) $round['serviceId']) ?? throw new RuntimeException('no such service');
            $container->get(ServiceOwnership::class)->returnToReseller($service, 'wrong person', AdminActor::environmentBotAdmin('990001'));
            $outcomes[] = 'returned';
        }
    } catch (ClaimException $e) {
        $outcomes[] = $e->error;
    } catch (Throwable $e) {
        $outcomes[] = 'UNHANDLED:'.$e::class.': '.$e->getMessage();
        // A broken entity manager must not fail the next rounds for the wrong reason.
        $container->get('doctrine')->resetManager();
        $entityManager = $container->get('doctrine')->getManager();
    }
}
echo json_encode($outcomes, JSON_THROW_ON_ERROR);
