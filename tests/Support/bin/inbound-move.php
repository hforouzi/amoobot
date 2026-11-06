<?php

declare(strict_types=1);

/*
 * Test helper (PR P4, docs/design/plan-inbound-change.md 8.2 P-1 ... P-7): one side of a race with the inbound move
 * runner, in its own PHP process (own DB connection), against a fake 3x-ui whose state is shared through <stateFile>.
 *
 * Usage: php inbound-move.php <mode> <id> <stateFile> <dir> <block> <delayMs> [startAt]
 * Modes:
 * - resume <runId>        app:plan:apply-inbounds <its plan> --resume=<runId>; prints "exit=<code>" and the output
 * - runner <runId>        InboundMoveRunner::process() itself, without the command's lock (a second runner on one item:
 *                         only the claims stand in its way); prints each outcome, then "exit=0" and the run's status
 * - undo <runId>          InboundMoveRunner::undo() itself (PRs P11b, P11c); prints each outcome, then "exit=0"
 * - task <runId>          app:automation:run --only=inbound-moves; prints "exit=<code>" and the output (the id only
 *                         names the service a <block> probes)
 * - timer <serviceId>     one run of the attach timer (InboundAttachScheduler::run()) (PR P11c); prints
 *                         "attached=<n> removed=<n> alerts=<n>" (the id only names the service a <block> probes)
 * - suspend <serviceId>   ServiceAdministration::suspend(); prints the outcome
 * - renew <serviceId>     ServiceRenewalService::renew() for 30 days and 50 GB (an unsaved renewal order), then flush;
 *                         prints "renewed"
 * <block>: "-", or "<before|after>|<METHOD path regex>|<nth>": at the nth panel request matching the regex, before it is
 *   answered or after, this process creates <dir>/ready, writes to <dir>/probe whether another connection could lock the
 *   service row right then (NOWAIT) and whether its own connection had a transaction open, then waits for <dir>/go (at
 *   most 120 s). A test may kill it there instead (the "kill -9" of 5.4).
 * <delayMs>: every panel request waits this long first (outside the panel's lock), so two processes overlap.
 * [startAt]: ParallelStart::startAt(): the moment to start.
 */

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Application\InboundMoveItem;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceRenewalService;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\MockHttpResponder;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';
require_once __DIR__.'/start-at.php';

[$mode, $id, $stateFile, $dir, $block, $delayMs, $startAt] = array_slice($argv ?? [], 1) + array_fill(0, 7, '');
$own = $entityManager->getConnection();

$fake = new Fake3xuiPanel();
$fake->stateFile = $stateFile;
if ((int) $delayMs > 0) {
    $fake->delays = ['(GET|POST) .*' => (int) $delayMs];
}
if ('-' !== $block && '' !== $block) {
    [$when, $pattern, $nth] = explode('|', $block) + ['', '', '1'];
    $seen = 0;
    $serviceId = in_array($mode, ['resume', 'runner', 'undo', 'task'], true)
        ? (int) $own->fetchOne('SELECT service_id FROM service_inbound_move WHERE run_id = ? ORDER BY id LIMIT 1', [(int) $id])
        : (int) $id;
    $fake->onRequest = static function (string $phase, string $method, string $path) use ($when, $pattern, $nth, &$seen, $dir, $own, $serviceId): void {
        if ($phase !== $when || 1 !== preg_match('#^'.$pattern.'$#', $method.' '.$path) || ++$seen !== (int) $nth) {
            return;
        }
        $other = DriverManager::getConnection($own->getParams());
        try {
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE NOWAIT', [$serviceId]);
            $other->rollBack();
            $row = 'row-free';
        } catch (Throwable) {
            $row = 'row-LOCKED';
        }
        $other->close();
        file_put_contents($dir.'/probe', $row.' '.($own->isTransactionActive() ? 'tx-OPEN' : 'no-tx')."\n", FILE_APPEND);
        touch($dir.'/ready');
        $until = microtime(true) + 120;
        while (!is_file($dir.'/go')) {
            if (microtime(true) > $until) {
                throw new RuntimeException('No go within 120 s.');
            }
            usleep(25_000);
        }
    };
}
$container->get(MockHttpResponder::class)->on('panel.example.test', $fake);

if ('' !== $startAt) {
    waitForStartAt($startAt);
}

if ('resume' === $mode) {
    $planId = (int) $own->fetchOne('SELECT plan_id FROM inbound_move_run WHERE id = ?', [(int) $id]);
    // boot-test-kernel.php makes the process quiet; the command's own output is what the test reads.
    putenv('SHELL_VERBOSITY=0');
    $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = 0;
    $application = new Application($container->get('kernel'));
    $application->setAutoExit(false);
    $output = new BufferedOutput();
    $code = $application->run(new ArrayInput(['command' => 'app:plan:apply-inbounds', 'planId' => (string) $planId, '--resume' => (string) $id]), $output);
    echo 'exit='.$code."\n".$output->fetch();

    return;
}

if ('runner' === $mode) {
    $pass = $container->get(InboundMoveRunner::class)->process((int) $id, onItem: static function (InboundMoveItem $item): void {
        printf("Service #%d: %s: \n", $item->serviceId, $item->outcome);
    });
    echo 'exit=0 '.$pass->runStatus."\n";

    return;
}
if ('undo' === $mode) {
    $pass = $container->get(InboundMoveRunner::class)->undo((int) $id, static function (InboundMoveItem $item): void {
        printf("Service #%d: %s: \n", $item->serviceId, $item->outcome);
    });
    echo 'exit=0 '.$pass->runStatus."\n";

    return;
}
if ('task' === $mode) {
    putenv('SHELL_VERBOSITY=0');
    $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = 0;
    $application = new Application($container->get('kernel'));
    $application->setAutoExit(false);
    $output = new BufferedOutput();
    $code = $application->run(new ArrayInput(['command' => 'app:automation:run', '--only' => 'inbound-moves']), $output);
    echo 'exit='.$code."\n".$output->fetch();

    return;
}

if ('timer' === $mode) {
    $run = $container->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
    printf("attached=%d removed=%d alerts=%d
", $run->attached, $run->foundRemoved, $run->alertsSent);

    return;
}

$service = $entityManager->find(VpnService::class, (int) $id) ?? throw new RuntimeException('no such service');
$administration = $container->get(ServiceAdministration::class);
if ('suspend' === $mode) {
    echo $administration->suspend($service)->name;

    return;
}
if ('renew' === $mode) {
    $container->get(ServiceRenewalService::class)->renew($service, (new Order())->setMetadata(['durationDays' => 30, 'trafficGb' => 50]));
    $entityManager->flush();
    echo 'renewed';

    return;
}
throw new RuntimeException('unknown mode '.$mode);
