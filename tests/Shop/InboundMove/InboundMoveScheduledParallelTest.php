<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P8, P-8 of docs/design/plan-inbound-change.md 8.2 with real PHP processes (tests/Support/bin/inbound-move.php) on
 * MariaDB and one fake 3x-ui whose state they share: the foreground command against the scheduled task. One refuses or
 * skips, never two runners on one item; a task killed with kill -9 leaves no lock, and a state the next pass finishes.
 */
#[Group('subprocess')]
final class InboundMoveScheduledParallelTest extends IntegrationTestCase
{
    use InboundMoveFixtures;
    use ParallelStart;
    use SubprocessAssertions;

    private string $stateFile;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->dir = sys_get_temp_dir().'/amoobot-p8-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->stateFile = $this->dir.'/panel.json';
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->removeStart();
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** P-8: the command in a pass, the task starts: it skips (success, no request), and the command finishes the run. */
    public function testATaskDuringAPassOfTheCommandSkips(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 2));
        $runId = $this->plannedRun();
        $command = $this->helper('resume', $runId, 'after|GET panel/api/clients/get/tg_p1_1|1');
        $command->start();
        $this->waitFor($this->dir.'/ready', $command);

        $task = $this->helper('task', $runId);
        $task->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($task, 'task'));
        $this->fake->loadState();
        self::assertSame(['GET panel/api/clients/get/tg_p1_1'], $this->fake->requestLines(), 'the task sent nothing');

        touch($this->dir.'/go');
        $command->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($command, 'command'));
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        $this->fake->loadState();
        self::assertCount(2, $this->attachCalls(), 'one attach per client');
    }

    /** @return iterable<string, array{string}> */
    public static function foregroundActions(): iterable
    {
        yield '--resume' => ['resume'];
        yield '--undo' => ['undo'];
        yield '--retire-now' => ['retire'];
    }

    /** P-8: the task in a pass: the command refuses at once and changes nothing, and the task finishes. */
    #[DataProvider('foregroundActions')]
    public function testACommandDuringThePassOfTheTaskRefuses(string $action): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 2));
        $runId = $this->plannedRun();
        $task = $this->helper('task', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $task->start();
        $this->waitFor($this->dir.'/ready', $task);
        $before = $this->moves();
        $input = match ($action) {
            'resume' => ['--resume' => (string) $runId],
            'undo' => ['--undo' => (string) $runId],
            default => ['--retire-now' => (string) $runId],
        };
        $this->fake->stateFile = $this->stateFile;
        $started = microtime(true);

        [$code, $output] = $this->command($input);

        self::assertLessThan(5.0, microtime(true) - $started, 'no waiting');
        self::assertSame(2, $code, $output);
        self::assertConsoleContains('a scheduled run is in progress, try again in a minute', $output);
        self::assertSame($before, $this->moves());

        touch($this->dir.'/go');
        $task->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($task, 'task'));
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')), 'no lock of a row, no transaction, during the panel step');
        self::assertSame(['switched', 'switched'], array_column($this->moves(), 'state'));
        $this->fake->loadState();
        self::assertCount(2, $this->attachCalls(), 'one attach per client');
    }

    /** An admin can stop a pass of the task: the service in hand is finished and no other is taken. */
    public function testAPauseDuringThePassOfTheTaskStopsItAfterTheServiceInHand(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 2));
        $runId = $this->plannedRun();
        $task = $this->helper('task', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $task->start();
        $this->waitFor($this->dir.'/ready', $task);

        [$code, $output] = $this->command(['--pause-run' => (string) $runId]);
        self::assertSame(0, $code, $output);
        touch($this->dir.'/go');
        $task->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($task, 'task'));
        self::assertSame(['switched', 'planned'], array_column($this->moves(), 'state'));
        self::assertSame('paused', $this->runs()[0]['status']);
    }

    /** P-8, started at the same moment: one works and the other refuses or skips; each service gets one attach. */
    public function testTheCommandAndTheTaskStartedTogetherNeverBothWork(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 4));
        $runId = $this->plannedRun();
        $processes = [$this->helper('resume', $runId, '-', 200, $this->startAt()), $this->helper('task', $runId, '-', 200, $this->startAt())];
        array_map(static fn (Process $p) => $p->start(), $processes);
        $this->releaseStart($processes);
        $outputs = array_map(function (Process $p): string {
            $p->wait();

            return self::assertSubprocessClean($p, 'one side');
        }, $processes);

        $refused = str_starts_with($outputs[0], 'exit=2');
        self::assertTrue($refused || str_starts_with($outputs[0], 'exit=0'), $outputs[0]);
        self::assertStringStartsWith('exit=0', $outputs[1]);
        $worked = $refused ? 'updated: 4' : 'updated: 0';
        self::assertConsoleContains($worked, $outputs[1]);
        self::assertSame(array_fill(0, 4, ['switched', 1]), array_map(static fn (array $m): array => [$m['state'], (int) $m['attempts']], $this->moves()));
        $this->fake->loadState();
        self::assertCount(4, $this->attachCalls());
        self::assertCount(4, array_unique($this->attachCalls()), 'one attach per client');
    }

    /** @return iterable<string, array{string, string, string}> where the task is killed, the phase, the state it leaves */
    public static function killPoints(): iterable
    {
        yield 'after the attach' => ['after|POST panel/api/clients/tg_p1_1/attach|1', 'move', 'attaching'];
        yield 'before the snapshot' => ['before|GET panel/api/clients/subLinks/.*|1', 'move', 'switched'];
        yield 'after the detach' => ['after|POST panel/api/clients/tg_p1_1/detach|1', 'retire', 'detaching'];
    }

    /** A pass killed with kill -9 leaves no lock (the command starts at once), and a state the next pass finishes. */
    #[DataProvider('killPoints')]
    public function testAKilledPassOfTheTaskIsFinishedByTheNextOne(string $stop, string $phase, string $left): void
    {
        $service = $this->movableService(1);
        $runId = $this->plannedRun();
        if ('retire' === $phase) {
            [$code, $output] = $this->command(['--resume' => (string) $runId]);
            self::assertSame(0, $code, $output);
            $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00'");
        }
        $this->fake->requests = [];
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();
        $task = $this->helper('task', $runId, $stop);
        $task->start();
        $this->waitFor($this->dir.'/ready', $task);
        $task->stop(0, 9);
        self::assertFalse($task->isRunning());
        self::assertSame($left, $this->moves()[0]['state']);

        // The lock went with the process; the claim is taken over after ServiceInboundAttacher::STALE_CLAIM.
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL');
        $next = $this->helper('task', $runId);
        $next->run();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($next, 'next pass'));
        $this->fake->loadState();
        $writes = $this->fake->requestLines();
        self::assertSame('retire' === $phase ? 'done' : 'switched', $this->moves()[0]['state']);
        self::assertCount('retire' === $phase ? 0 : 1, array_keys($writes, 'POST panel/api/clients/tg_p1_1/attach'), 'no second attach');
        self::assertCount('retire' === $phase ? 1 : 0, array_keys($writes, 'POST panel/api/clients/tg_p1_1/detach'), 'no second detach');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        self::assertNotNull($row['last_access_info_synced_at'], 'links refreshed');
        self::assertSame('retire' === $phase ? [2] : [1, 2], $this->fake->clients['tg_p1_1']['inboundIds']);
    }

    private function plannedRun(): int
    {
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($this->gold), new InboundMoveScope());
        self::assertIsArray($created, is_string($created) ? $created : '');
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();

        return $created[0];
    }

    private function helper(string $mode, int $id, string $block = '-', int $delayMs = 0, string $startAt = ''): Process
    {
        return new Process([$this->php(), 'tests/Support/bin/inbound-move.php', $mode, (string) $id, $this->stateFile, $this->dir, $block, (string) $delayMs, $startAt], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
    }

    private function php(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
    }

    private function waitFor(string $file, Process $process): void
    {
        $until = microtime(true) + 120;
        while (!is_file($file)) {
            if (!$process->isRunning()) {
                self::fail(sprintf("The process ended before %s.\n--- stdout ---\n%s\n--- stderr ---\n%s", basename($file), mb_substr($process->getOutput(), -4000), mb_substr($process->getErrorOutput(), -4000)));
            }
            if (microtime(true) > $until) {
                self::fail('Not reached within 120 s: '.basename($file));
            }
            usleep(25_000);
        }
    }
}
