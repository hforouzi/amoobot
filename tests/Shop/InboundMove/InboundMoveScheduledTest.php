<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\Reseller;
use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\InboundMoveLock;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Application\InboundMoveTaskRun;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PR P8, the scheduled task inbound-moves of docs/design/plan-inbound-change.md 5.2 (app:automation:run
 * --only=inbound-moves), on the fake modern 3x-ui:
 * - it continues planned and running runs: phase 1, a due retirement after the grace period, a retry after its back-off;
 * - nothing at all with the switch off, for paused, cancelled or finished runs, or while another pass holds the lock;
 * - a panel that does not answer pauses the run with one alert, and the next pass leaves it alone;
 * - the time budget leaves the rest for the next pass; one run that throws does not stop the others;
 * - the lock in both directions (in one process here; between real processes in InboundMoveScheduledParallelTest);
 * - D5 and masked errors, with zend.exception_ignore_args 0 and 1.
 */
final class InboundMoveScheduledTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // admin_user is not purged: linked admins left by an earlier Mini App admin test would also get the alerts
        // counted here (TELEGRAM_ADMIN_CHAT_ID alone is expected).
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->setUpMove();
    }

    public function testAPlannedRunIsMovedByTheTaskAndTheRetireFollowsAfterTheGracePeriod(): void
    {
        $service = $this->movableService(1);
        $runId = $this->plannedRun();
        self::assertSame('planned', $this->runs()[0]['status']);

        [$code, $output] = $this->task();

        self::assertSame(0, $code, $output);
        self::assertSame('switched', $this->moves()[0]['state']);
        self::assertSame('running', $this->runs()[0]['status'], 'moved: the old inbound waits for its grace period');
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
        self::assertConsoleContains('updated: 1', $output);

        // Within the grace period a pass asks the panel nothing.
        $this->fake->requests = [];
        [$code] = $this->task();
        self::assertSame(0, $code);
        self::assertSame([], $this->requestLog(), 'nothing due: no request');

        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE run_id = ?", [$runId]);
        [$code, $output] = $this->task();

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNotNull($this->moves()[0]['detached_at']);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertCount(1, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true), 'B only');
    }

    public function testARetryWaitsForItsBackOffAndThenTheTaskDoesIt(): void
    {
        $this->movableService(1);
        $this->plannedRun();
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'busy'));

        [$code, $output] = $this->task();

        self::assertSame(0, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', 1], [$move['state'], (int) $move['attempts']]);
        self::assertGreaterThan(date('Y-m-d H:i:s'), (string) $move['next_attempt_at']);

        $this->fake->requests = [];
        [$code] = $this->task();
        self::assertSame(0, $code);
        self::assertSame([], $this->requestLog(), 'its back-off is not over');

        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00'");
        [$code, $output] = $this->task();

        self::assertSame(0, $code, $output);
        self::assertSame('switched', $this->moves()[0]['state']);
    }

    public function testGivingUpFollowsTheAttachSettingsWithOneAlert(): void
    {
        $this->movableService(1);
        $this->plannedRun();
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'busy'), 5);
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->task();
        $this->task();

        self::assertSame(0, $code, $output);
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertNull($this->moves()[0]['next_attempt_at']);
        self::assertCount(1, $this->attachCalls(), 'given up: not tried again');
        self::assertSame('done', $this->runs()[0]['status'], 'nothing can change any more');
        self::assertCount(1, array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => 1 === preg_match('/Service ID: \d+/', $t)), 'one alert for the service');
    }

    public function testWithTheSwitchOffNothingHappens(): void
    {
        $this->movableService(1);
        $this->plannedRun();
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $before = $this->everything();
        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);

        try {
            [$code, $output] = $this->task();
        } finally {
            $held->release();
        }

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->requestLog());
        self::assertSame($before, $this->everything());
        self::assertSame(InboundMoveTaskRun::SWITCHED_OFF, $this->scheduler()->run()->outcome);
    }

    public function testPausedCancelledAndFinishedRunsAreLeftAlone(): void
    {
        $this->movableService(1);
        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true]);
        self::assertSame(0, $code, $output);
        $runId = (int) $this->runs()[0]['id'];
        $db = $this->em->getConnection();
        // Something due in each: a planned move and a failed one with a retry waiting.
        $this->movableService(2);
        $db->executeStatement("UPDATE service_inbound_move SET state = 'failed', next_attempt_at = '2000-01-01 00:00:00', attempts = 1, detach_after = NULL");
        foreach ([['paused', 'admin', true], ['cancelled', null, false], ['done', null, false]] as [$status, $reason, $open]) {
            $db->executeStatement('UPDATE inbound_move_run SET status = ?, pause_reason = ?, open_plan_id = ? WHERE id = ?', [$status, $reason, $open ? $this->gold->getId() : null, $runId]);
            $before = $this->everything();
            $this->fake->requests = [];

            [$code, $output] = $this->task();

            self::assertSame(0, $code, $output);
            self::assertSame([], $this->requestLog(), $status);
            self::assertSame($before, $this->everything(), $status);
        }
    }

    public function testAPauseAfterTheTaskPickedTheRunIsNotOverwritten(): void
    {
        $this->movableService(1);
        $runId = $this->plannedRun();
        $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', pause_reason = 'admin' WHERE id = ?", [$runId]);

        // The runner told to leave paused runs alone: what the task calls, after its own query saw "running".
        $pass = self::getContainer()->get(\App\Provisioning\Application\InboundMoveRunner::class)->process($runId, leavePaused: true);

        self::assertSame(\App\Provisioning\Application\InboundMovePass::NOT_OPEN, $pass->outcome);
        self::assertSame(['paused', 'admin'], [$this->runs()[0]['status'], $this->runs()[0]['pause_reason']]);
        self::assertSame([], $this->requestLog());
    }

    public function testACancelledRunWithMovedServicesStillRetiresThem(): void
    {
        $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        [$code, $output] = $this->command(['--cancel' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame('running', $this->runs()[0]['status'], 'P6: open while a moved service waits for its retire');
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00'");

        [$code, $output] = $this->task();

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
    }

    public function testAPanelThatDoesNotAnswerPausesTheRunWithOneAlertAndTheNextPassLeavesIt(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 6));
        $this->plannedRun();
        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Could not resolve host'), times: 100);
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->task();

        self::assertSame(0, $code, 'a panel that is down is an alert, not a failed job: '.$output);
        $run = $this->runs()[0];
        self::assertSame(['paused', 'panel not answering: 5 failed panel reads in a row'], [$run['status'], $run['pause_reason']]);
        self::assertSame(array_merge(array_fill(0, 5, 'failed'), ['planned']), array_column($this->moves(), 'state'));
        self::assertSame([], $this->panelWrites());
        $paused = fn (): array => array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_starts_with($t, InboundAttachAlerts::MOVE_PAUSED_TITLE)));
        self::assertCount(1, $paused());

        $this->fake->requests = [];
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00' WHERE state = 'failed'");
        [$code] = $this->task();

        self::assertSame(0, $code);
        self::assertSame([], $this->requestLog(), 'paused: only an admin resumes it');
        self::assertCount(1, $paused());
    }

    public function testRunsGoInOrderOfIdAndTheTimeBudgetLeavesTheRestForTheNextPass(): void
    {
        $this->movableService(1);
        $first = $this->plannedRun();
        $silver = $this->createPlan(100000, $this->managed($this->de))->setTitle('Silver');
        $this->em->flush();
        $this->planService($silver, $this->managed($this->de), n: 2);
        $this->sellOn($silver, $this->managed($this->nl));
        $second = $this->plannedRun($silver);

        $spent = $this->scheduler()->run(budgetSeconds: 0);

        self::assertTrue($spent->outOfTime);
        self::assertSame(['planned', 'planned'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->requestLog(), 'no service is started after the budget');

        $task = $this->scheduler()->run();

        self::assertFalse($task->outOfTime);
        self::assertSame([2, 2], [$task->runs, $task->moved]);
        $attaches = $this->attachCalls();
        self::assertSame(['POST panel/api/clients/tg_p1_1/attach', 'POST panel/api/clients/tg_p1_2/attach'], $attaches, 'run #'.$first.' first, then #'.$second);
    }

    public function testTheBudgetEndingInsideARunStopsAtAServiceAndTheNextPassFinishes(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 2));
        $this->plannedRun();
        $spend = 0;
        $this->fake->onRequest = function (string $phase, string $method, string $path) use (&$spend): void {
            if ('after' === $phase && str_ends_with($path, '/attach')) {
                ++$spend;
                usleep(1_200_000);
            }
        };

        $task = $this->scheduler()->run(budgetSeconds: 1);
        $this->fake->onRequest = null;

        self::assertTrue($task->outOfTime);
        self::assertSame(1, $spend, 'the second service was not started');
        self::assertSame(['switched', 'planned'], array_column($this->moves(), 'state'));
        self::assertSame('running', $this->runs()[0]['status'], 'neither paused nor finished');

        self::assertFalse($this->scheduler()->run()->outOfTime);
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
    }

    public function testARunThatThrowsDoesNotStopTheNextOneAndFailsTheJob(): void
    {
        $this->movableService(1);
        $broken = $this->plannedRun();
        $silver = $this->createPlan(100000, $this->managed($this->de))->setTitle('Silver');
        $this->em->flush();
        $this->planService($silver, $this->managed($this->de), n: 2);
        $this->sellOn($silver, $this->managed($this->nl));
        $this->plannedRun($silver);
        $db = $this->em->getConnection();
        $plan = (int) $this->gold->getId();
        // The runner throws "The run's plan is gone" for this run.
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $db->executeStatement('UPDATE inbound_move_run SET plan_id = 999999999, open_plan_id = 999999999 WHERE id = ?', [$broken]);
        $this->em->clear();

        try {
            [$code, $output] = $this->task();
        } finally {
            $db->executeStatement('UPDATE inbound_move_run SET plan_id = ?, open_plan_id = IF(open_plan_id IS NULL, NULL, ?) WHERE id = ?', [$plan, $plan, $broken]);
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        self::assertSame(1, $code, $output);
        self::assertConsoleContains('failed: 1', $output);
        self::assertSame(['planned', 'switched'], array_column($this->moves(), 'state'));
    }

    public function testADryRunCountsAndChangesNothing(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 2));
        $this->plannedRun();
        $before = $this->everything();

        $task = $this->scheduler()->run(dryRun: true);
        [$code, $output] = $this->task(['--dry-run' => true]);

        self::assertSame([1, 2], [$task->runs, $task->due]);
        self::assertSame(0, $code, $output);
        self::assertConsoleContains('checked: 2', $output);
        self::assertSame([], $this->requestLog());
        self::assertSame($before, $this->everything());
    }

    public function testTheTaskIsPartOfAllAndAnUnknownNameStillFails(): void
    {
        $this->movableService(1);
        $this->plannedRun();

        [$code, $output] = $this->task(['--only' => 'all']);
        self::assertSame(0, $code, $output);
        self::assertSame('switched', $this->moves()[0]['state']);

        [$code, $output] = $this->task(['--only' => 'inbound-move']);
        self::assertSame(1, $code);
        self::assertConsoleContains('attach-inbounds, inbound-moves, all', $output);
    }

    public function testWhileAPassHoldsTheLockTheTaskSkipsQuietlyWithOneRedactedLogLine(): void
    {
        $this->movableService(1);
        $this->plannedRun();
        $logger = self::getContainer()->get(RecordingLogger::class);
        $logs = count($logger->records);
        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);
        $before = $this->everything();

        try {
            [$code, $output] = $this->task();
        } finally {
            $held->release();
        }

        self::assertSame(0, $code, 'a skip is not a failure: '.$output);
        self::assertSame([], $this->requestLog());
        self::assertSame($before, $this->everything());
        $lines = array_values(array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => 'inbound_move_task_skipped' === $r['message']));
        self::assertCount(1, $lines);
        self::assertSame(['reason' => 'another pass holds the lock'], $lines[0]['context']);

        [$code] = $this->task();
        self::assertSame(0, $code);
        self::assertSame('switched', $this->moves()[0]['state'], 'released: the next pass works');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function lockedActions(): iterable
    {
        yield '--apply' => [['--apply' => true]];
        yield '--resume' => [['--resume' => 'run']];
        yield '--retire-now' => [['--retire-now' => 'run']];
        yield '--retire-now --resume' => [['--retire-now' => 'run', '--resume' => 'run']];
        yield '--undo' => [['--undo' => 'run']];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('lockedActions')]
    public function testWhileThePassOfTheTaskHoldsTheLockTheCommandRefusesAtOnce(array $input): void
    {
        $this->movableService(1);
        $runId = $this->plannedRun();
        if (['--apply' => true] === $input) {
            $this->em->getConnection()->executeStatement('DELETE FROM service_inbound_move');
            $this->em->getConnection()->executeStatement('DELETE FROM inbound_move_run');
        }
        $input = array_map(static fn (mixed $v): mixed => 'run' === $v ? (string) $runId : $v, $input);
        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);
        $before = $this->everything();
        $started = microtime(true);

        try {
            [$code, $output] = $this->command($input);
        } finally {
            $held->release();
        }

        self::assertLessThan(5.0, microtime(true) - $started, 'no waiting');
        self::assertSame(2, $code, $output);
        self::assertConsoleContains('a scheduled run is in progress, try again in a minute', $output);
        self::assertSame([], $this->requestLog());
        self::assertSame($before, $this->everything());

        [$code, $output] = $this->command($input);
        self::assertStringNotContainsString('scheduled run', self::collapseWhitespace($output), 'released');
    }

    public function testPauseCancelAndTheDryRunWorkDuringAPassAndTheCommandReleasesTheLock(): void
    {
        $this->movableService(1);
        $runId = $this->plannedRun();
        $held = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($held);

        try {
            [$dry, $dryOutput] = $this->command([]);
            [$pause, $pauseOutput] = $this->command(['--pause-run' => (string) $runId]);
            [$cancel, $cancelOutput] = $this->command(['--cancel' => (string) $runId]);
        } finally {
            $held->release();
        }

        self::assertSame([0, 0, 0], [$dry, $pause, $cancel], $dryOutput.$pauseOutput.$cancelOutput);
        self::assertSame('cancelled', $this->runs()[0]['status']);

        // A command that ended (even refused or failed) leaves the lock free.
        $this->command(['--resume' => '999999']);
        $again = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($again);
        $again->release();
    }

    public function testTwoAcquisitionsConflictAndOneReleaseFreesIt(): void
    {
        $lock = self::getContainer()->get(InboundMoveLock::class);
        $first = $lock->acquire();
        self::assertNotNull($first);
        self::assertNull($lock->acquire());
        $first->release();
        $first->release();
        $second = self::getContainer()->get(InboundMoveLock::class)->acquire();
        self::assertNotNull($second);
        $second->release();
    }

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    /**
     * D5 and masked errors: a reseller-sold service, a claimed one and a panel quoting them back: what the task prints,
     * logs, stores, audits and alerts holds ids and titles only, and it writes no owner or reseller row.
     */
    #[DataProvider('ignoreArgs')]
    public function testNothingSecretLeavesTheTaskAndNoOwnerIsTouched(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $reseller = (new Reseller($this->createUser('8930002')->setName('Reseller Rostam')))->setDisplayName('Rostam Shop');
            $this->em->persist($reseller);
            $sold = $this->movableService(1)->setSoldByReseller($reseller);
            $claimed = $this->movableService(2)->setSoldByReseller($reseller)->setUser($this->createUser('8930003')->setName('Claimer Sohrab'));
            $this->em->flush();
            $leak = static fn (string $email, string $uuid, string $sub): string => sprintf('client %s (%s, sub %s) at %s/secret/panel/api/clients/x/%s token %s vless://%s@h', $email, $uuid, $sub, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN, $uuid);
            $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
            $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, $leak('tg_p1_1', (string) $sold->getClientUuid(), (string) $sold->getSubId())));
            $this->fake->script('GET', 'panel/api/clients/get/tg_p1_2', static function () use ($leak, $claimed): MockResponse {
                throw new \RuntimeException($leak('tg_p1_2', (string) $claimed->getClientUuid(), (string) $claimed->getSubId()));
            });
            $this->plannedRun();
            $db = $this->em->getConnection();
            $owners = static fn (): array => [$db->fetchAllAssociative('SELECT * FROM `user` ORDER BY id'), $db->fetchAllAssociative('SELECT * FROM reseller ORDER BY id'), $db->fetchFirstColumn('SELECT user_id FROM vpn_service ORDER BY id')];
            $ownersBefore = $owners();
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);
            $alerts = count($this->http->telegramTexts());

            [$code, $output] = $this->task();

            self::assertSame(0, $code, $output);
            self::assertSame(['failed', 'failed'], array_column($this->moves(), 'state'), 'both really failed');
            self::assertSame($ownersBefore, $owners());
            $texts = [
                'output' => $output,
                // The existing per-request log of ModernPanelHttpClient (sanaei_modern_request*) is not this task's.
                'logs' => implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => !str_starts_with((string) $r['message'], 'sanaei_modern_request')))),
                'stored errors' => implode("\n", array_column($this->moves(), 'last_error')),
                'audit' => (string) json_encode($this->audits(), JSON_UNESCAPED_SLASHES),
                'alerts' => implode("\n", array_slice($this->http->telegramTexts(), $alerts)),
            ];
            self::assertNotSame('', $texts['alerts'], 'given up: alerts were sent');
            foreach ($texts as $where => $text) {
                foreach ([...$this->secretsOf($sold), ...$this->secretsOf($claimed), 'Rostam', 'Sohrab', 'Owner Kaveh', '8930002', '8930003', '8940001', '/secret/', 'clients/x/tg_'] as $secret) {
                    self::assertStringNotContainsString($secret, $text, $where);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    public function testAnErrorOfTheRunIsLoggedMasked(): void
    {
        $this->movableService(1);
        $broken = $this->plannedRun();
        $logger = self::getContainer()->get(RecordingLogger::class);
        $logs = count($logger->records);
        $db = $this->em->getConnection();
        $plan = (int) $this->gold->getId();
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $db->executeStatement('UPDATE inbound_move_run SET plan_id = 999999999, open_plan_id = 999999999 WHERE id = ?', [$broken]);
        $this->em->clear();

        try {
            $this->scheduler()->run();
        } finally {
            $db->executeStatement('UPDATE inbound_move_run SET plan_id = ?, open_plan_id = IF(open_plan_id IS NULL, NULL, ?) WHERE id = ?', [$plan, $plan, $broken]);
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $lines = array_values(array_filter(array_slice($logger->records, $logs), static fn (array $r): bool => 'inbound_move_task_run_failed' === $r['message']));
        self::assertCount(1, $lines);
        self::assertSame(['run_id', 'class', 'error'], array_keys($lines[0]['context']));
    }

    /** The task's own code names nothing of the sale path; the runner's is tested by InboundMoveLockHoldTest (P-7). */
    public function testTheTaskStaysOffTheSalePath(): void
    {
        foreach (['InboundMoveScheduler', 'InboundMoveLock', 'InboundMoveHeldLock', 'InboundMoveTaskRun'] as $class) {
            $code = php_strip_whitespace(dirname(__DIR__, 3).'/src/Provisioning/Application/'.$class.'.php');
            self::assertSame(0, preg_match('/PaymentSettlement|VpnProvisioning|PlanSaleRule|PlanCatalog|Wallet|FOR UPDATE|`order`|\bpayment\b/', $code), $class);
        }
    }

    private function plannedRun(?\App\Entity\Plan $plan = null): int
    {
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($plan ?? $this->gold), new InboundMoveScope());
        self::assertIsArray($created, is_string($created) ? $created : '');
        $this->fake->requests = [];

        return $created[0];
    }

    private function scheduler(): InboundMoveScheduler
    {
        return self::getContainer()->get(InboundMoveScheduler::class);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string} exit code, output
     */
    private function task(array $input = []): array
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:automation:run'));
        $code = $tester->execute($input + ['--only' => 'inbound-moves']);
        $this->em->clear();

        return [$code, $tester->getDisplay()];
    }

    /** @return list<string> read through a method: PHPStan keeps the type of a property a test just assigned */
    private function requestLog(): array
    {
        return $this->fake->requestLines();
    }

    /** Every row the task could change, to compare a pass that must do nothing. @return array<string, mixed> */
    private function everything(): array
    {
        $db = $this->em->getConnection();

        return [
            'runs' => $db->fetchAllAssociative('SELECT * FROM inbound_move_run ORDER BY id'),
            'moves' => $db->fetchAllAssociative('SELECT * FROM service_inbound_move ORDER BY id'),
            'services' => $db->fetchAllAssociative('SELECT * FROM vpn_service ORDER BY id'),
            'audit' => $db->fetchAllAssociative('SELECT * FROM admin_audit_log ORDER BY id'),
        ];
    }
}
