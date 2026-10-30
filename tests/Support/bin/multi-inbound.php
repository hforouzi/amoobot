<?php

declare(strict_types=1);

/*
 * Test helper: one multi-inbound step in its own PHP process (own DB connection), against a fake 3x-ui whose state is
 * shared with the other processes through <stateFile> (Fake3xuiPanel::$stateFile), so a test can race two of them.
 * Every request to the panel waits <delayMs> first (outside the panel's lock), so the two overlap.
 *
 * Usage: php multi-inbound.php settle <paymentId> <stateFile> <delayMs> [scenario]  prints the settlement outcome
 *        php multi-inbound.php retry-attach <serviceId> <stateFile> <delayMs>        prints the retry outcome
 *        php multi-inbound.php attach-scheduled - <stateFile> <delayMs>              prints "attached=N" of a scheduled run
 *        php multi-inbound.php attach-run - <stateFile> <delayMs>                    prints "attached=N removed=N alerts=N"
 *        php multi-inbound.php edit-plan <planId> <stateFile> <delayMs>              drops the plan's additional inbounds
 *
 * Scenarios (M2c), scripted on this process's fake (the scripts act on the shared state):
 * - read-blip: the add lands on the primary only, the attach works, every read after it fails (F2);
 * - budget: the add lands on the primary only, the batch attach is refused, each single attach takes 25 s of a
 *   MockClock (F5: one fits in the inline budget).
 */

use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\MockHttpResponder;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$mode, $id, $stateFile, $delayMs, $scenario] = array_slice($argv ?? [], 1) + array_fill(0, 5, '');

$fake = new Fake3xuiPanel();
$fake->stateFile = $stateFile;
$fake->delays = ['(GET|POST) .*' => (int) $delayMs];
$container->get(MockHttpResponder::class)->on('panel.example.test', $fake);

if (in_array($scenario, ['read-blip', 'budget'], true)) {
    $fake->script('POST', 'panel/api/clients/add', static function (string $m, string $u, array $options) use ($fake): MockResponse {
        $body = json_decode((string) $options['body'], true);
        $fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

        return Fake3xuiPanel::envelope(true);
    });
}
if ('read-blip' === $scenario) {
    $fake->script('POST', null, static function () use ($fake): MockResponse {
        $fake->script('GET', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);

        return Fake3xuiPanel::envelope(true);
    }, 1, true);
}
if ('budget' === $scenario) {
    $clock = new MockClock();
    Clock::set($clock);
    $fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'one inbound is broken'));
    $fake->script('POST', null, static function () use ($clock): MockResponse {
        $clock->sleep(25);

        return Fake3xuiPanel::envelope(true);
    }, 10, true);
}

if ('settle' === $mode) {
    $payment = $entityManager->find(Payment::class, (int) $id) ?? throw new RuntimeException('no such payment');
    $started = microtime(true);
    echo $container->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'concurrency_test')->outcome->value;
    // How long this process waited and worked, for a test of what is done under the settlement's locks.
    fwrite(STDERR, sprintf('elapsed=%.2f', microtime(true) - $started));

    return;
}

if ('attach-scheduled' === $mode) {
    echo 'attached='.$container->get(InboundAttachScheduler::class)->run(500, false, 50, 0)->attached;

    return;
}

if ('attach-run' === $mode) {
    $run = $container->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
    printf('attached=%d removed=%d alerts=%d', $run->attached, $run->foundRemoved, $run->alertsSent);

    return;
}

if ('edit-plan' === $mode) {
    $plan = $entityManager->find(Plan::class, (int) $id) ?? throw new RuntimeException('no such plan');
    $plan->setAdditionalInbounds([]);
    $entityManager->flush();
    echo 'edited';

    return;
}

$service = $entityManager->find(VpnService::class, (int) $id) ?? throw new RuntimeException('no such service');
echo $container->get(ServiceInboundAttacher::class)->retry($service, 'concurrency_test')->outcome;
