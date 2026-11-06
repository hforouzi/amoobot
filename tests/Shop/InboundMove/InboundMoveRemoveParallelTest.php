<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P11c, the two-process tests of docs/design/plan-inbound-add-remove.md 11.1 for kind=remove, on MariaDB with real PHP
 * processes (tests/Support/bin/inbound-move.php, multi-inbound.php) and one fake 3x-ui whose state they share: the attach
 * timer stopped between its read and its write, "Retry attach" on the same row in both orders, kill -9 at each row of 5.4
 * (and in the undo), a link reset and a Regenerate during a step, and two runners on one run.
 */
#[Group('subprocess')]
final class InboundMoveRemoveParallelTest extends IntegrationTestCase
{
    use InboundRemoveFixtures;
    use ParallelStart;
    use SubprocessAssertions;

    private const NEW_UUID = '99999999-8888-4777-a666-555555555555';
    private const NEW_SUB = 'p11resetsub000000001';

    private string $stateFile;
    private string $dir;
    private string $timerDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemove();
        $this->dir = sys_get_temp_dir().'/amoobot-p11c-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->timerDir = $this->dir.'/timer';
        mkdir($this->timerDir);
        $this->stateFile = $this->dir.'/panel.json';
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->removeStart();
        foreach ([...(glob($this->timerDir.'/*') ?: []), ...(glob($this->dir.'/*') ?: [])] as $file) {
            is_file($file) ? @unlink($file) : null;
        }
        @rmdir($this->timerDir);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testTwoRunnersOnOneRunRemoveOnce(): void
    {
        foreach (range(1, 4) as $n) {
            $this->onBoth($n);
        }
        $runId = $this->share()->plannedRemove();

        $runners = [$this->helper('runner', $runId, '-', 200, $this->startAt()), $this->helper('runner', $runId, '-', 200, $this->startAt())];
        array_map(static fn (Process $p) => $p->start(), $runners);
        $this->releaseStart($runners);
        $outputs = array_map(function (Process $p): string { $p->wait(); return self::assertSubprocessClean($p, 'runner'); }, $runners);

        self::assertSame(4, self::consoleCount(implode("\n", $outputs), ': removed: '), implode("\n---\n", $outputs));
        self::assertSame(array_fill(0, 4, ['done', 1]), array_map(static fn (array $m): array => [$m['state'], (int) $m['attempts']], $this->moves()));
        $this->fake->loadState();
        foreach (range(1, 4) as $n) {
            self::assertSame([1], $this->onPanel($n));
            self::assertCount(1, array_keys($this->fake->requestLines(), 'POST panel/api/clients/tg_p1_'.$n.'/detach'), 'one detach per client');
        }
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'), 'finished once');
    }

    /** No lock and no transaction during the panel step, and the row has already left "attached". */
    public function testThePanelStepHoldsNoLock(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, 'before|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')));
        touch($this->dir.'/go');
        $runner->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertNull($this->nlRow($service));
    }

    /**
     * The timer read the rows (attached), then stops before it reads the panel. The whole remove runs. Then the timer
     * finds D gone on the panel: it must not take that for a hand removal.
     */
    public function testTheTimerThatReadTheRowBeforeTheRemoveWritesNothing(): void
    {
        $service = $this->dueOnBoth();
        $runId = $this->share()->plannedRemove();
        $timer = $this->timer('before|GET panel/api/clients/get/tg_p1_1|1', (int) $service->getId());
        $timer->start();
        $this->waitFor($this->timerDir.'/ready', $timer);

        $runner = $this->helper('resume', $runId);
        $runner->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertNull($this->nlRow($service));
        touch($this->timerDir.'/go');
        $timer->wait();

        self::assertSame('attached=0 removed=0 alerts=0', self::lastLine(self::assertSubprocessClean($timer, 'timer')));
        $this->assertStaysRemoved($service);
    }

    /** The same, with the remove stopped between its detach and its write: the row exists, held, when the timer writes. */
    public function testTheTimerStoppedBetweenReadAndWriteMeetsAHeldRow(): void
    {
        $service = $this->dueOnBoth();
        $runId = $this->share()->plannedRemove();
        $timer = $this->timer('before|GET panel/api/clients/get/tg_p1_1|1', (int) $service->getId());
        $timer->start();
        $this->waitFor($this->timerDir.'/ready', $timer);
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);

        touch($this->timerDir.'/go');
        $timer->wait();

        self::assertSame('attached=0 removed=0 alerts=0', self::lastLine(self::assertSubprocessClean($timer, 'timer')));
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null, 'its update is conditional on the status it read');
        touch($this->dir.'/go');
        $runner->wait();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame('done', $this->moves()[0]['state']);
        $this->assertStaysRemoved($service);
    }

    /** The timer starts while the remove is on the panel: a held row is not due at all. */
    public function testATimerRunDuringTheRemoveDoesNotReadTheHeldRow(): void
    {
        $service = $this->dueOnBoth();
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        $timer = $this->timer('-', (int) $service->getId());
        $timer->run();

        self::assertSame('attached=0 removed=0 alerts=0', self::lastLine(self::assertSubprocessClean($timer, 'timer')));
        touch($this->dir.'/go');
        $runner->wait();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $this->assertStaysRemoved($service);
    }

    /** "Retry attach" while the run holds the row: the row is out of its reach, and it calls nothing. */
    public function testRetryAttachFindsTheRowHeldByTheRun(): void
    {
        $service = $this->onBoth(1, rowStatus: 'failed');
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, 'after|GET panel/api/clients/get/tg_p1_1|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        $retry = $this->retryAttach((int) $service->getId(), 0);
        $retry->run();

        self::assertSame('nothing_to_do', self::assertSubprocessClean($retry, 'retry-attach'));
        touch($this->dir.'/go');
        $runner->wait();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        self::assertSame([], $this->attachCalls());
        self::assertNull($this->nlRow($service));
    }

    /** The other order: the run finds the attacher's fresh claim and waits; the next pass removes what it attached. */
    public function testTheRunWaitsForARetryAttachInFlight(): void
    {
        $service = $this->onBoth(1, rowStatus: 'failed');
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $runId = $this->share()->plannedRemove();
        $retry = $this->retryAttach((int) $service->getId(), 3000);
        $retry->start();
        $this->waitForRow($service, 'pending', $retry);

        $runner = $this->helper('resume', $runId);
        $runner->run();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame(['planned', 0], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']], 'busy, not a skip');
        $retry->wait();
        self::assertSame('done', self::assertSubprocessClean($retry, 'retry-attach'));
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);

        $again = $this->helper('resume', $runId);
        $again->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($again, 'again'));
        self::assertSame('done', $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
    }

    /** Both at the same moment, whichever wins the claim: no deadlock, one row or none, and the panel agrees. */
    public function testTheRunAndRetryAttachAtTheSameMoment(): void
    {
        $service = $this->onBoth(1, rowStatus: 'failed');
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, '-', 300, $this->startAt());
        $retry = new Process([$this->php(), 'tests/Support/bin/multi-inbound.php', 'retry-attach', (string) $service->getId(), $this->stateFile, '300'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $runner->start();
        $retry->start();
        $this->releaseStart([$runner]);
        $runner->wait();
        $retry->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertContains(self::assertSubprocessClean($retry, 'retry-attach'), ['done', 'in_progress', 'nothing_to_do']);
        $finish = $this->helper('resume', $runId);
        $finish->run();
        self::assertStringStartsWith('exit=', self::assertSubprocessClean($finish, 'finish'));
        self::assertSame('done', $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
    }

    /** @return iterable<string, array{string, string, list<int>, string|null, bool}> where it dies, the move left, the client's inbounds, the row, the guard */
    public static function killPoints(): iterable
    {
        yield 'after the claim' => ['before|GET panel/api/clients/get/tg_p1_1|1', 'detaching', [1, 2], 'detached', false];
        yield 'after the detach' => ['after|POST panel/api/clients/tg_p1_1/detach|1', 'detaching', [1], 'detached', false];
        yield 'after the write' => ['before|GET panel/api/clients/subLinks/.*|1', 'done', [1], null, true];
    }

    /**
     * kill -9 at each remove row of 5.4: the next pass finishes it, with exactly one detach in all, and the attach timer
     * touches nothing in between.
     *
     * @param list<int> $panel
     */
    #[DataProvider('killPoints')]
    public function testAKilledRunnerIsFinishedByTheNextPass(string $stop, string $left, array $panel, ?string $row, bool $guard): void
    {
        $service = $this->onBoth(1);
        $before = $this->identityOf($service, 1);
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, $stop);
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        self::assertFalse($runner->isRunning());

        self::assertSame($left, $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame($panel, $this->onPanel(1));
        self::assertSame($row, $this->nlRow($service)['status'] ?? null);
        self::assertSame($guard, $this->guardOn($service));

        $timer = $this->timer('-', (int) $service->getId());
        $timer->run();
        self::assertSame('attached=0 removed=0 alerts=0', self::lastLine(self::assertSubprocessClean($timer, 'timer')), 'nothing for the timer here');
        $this->staleClaims();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->nlRow($service));
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        self::assertCount(1, $this->detachCalls(), 'exactly one detach in all');
        self::assertSame($before, $this->identityOf($service, 1));
        self::assertFalse($this->guardOn($service));
    }

    /** The inbound can be deleted after a killed run was finished: nothing is left behind. */
    public function testAKilledRunnerLeavesNothingThatHoldsTheInbound(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        $this->staleClaims();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]));
    }

    /** @return iterable<string, array{string, string, list<int>, string|null}> where the undo dies, the move left, the client's inbounds, the row */
    public static function undoKillPoints(): iterable
    {
        yield 'claimed, before the read' => ['before|GET panel/api/clients/get/tg_p1_1|1', 'attaching', [1], null];
        yield 'attached, before the write' => ['after|POST panel/api/clients/tg_p1_1/attach|1', 'attaching', [1, 2], null];
        yield 'written, before the links' => ['before|GET panel/api/clients/subLinks/.*|1', 'undone', [1, 2], 'attached'];
    }

    /**
     * kill -9 in the undo: --undo again finishes it with one attach in all, and a pass of the run never reads the move as a
     * remove cut off in the middle.
     *
     * @param list<int> $panel
     */
    #[DataProvider('undoKillPoints')]
    public function testAKilledUndoIsFinishedByTheNextUndo(string $stop, string $left, array $panel, ?string $row): void
    {
        $service = $this->onBoth(1);
        [$code, $output] = $this->remove(['--apply' => true]);
        self::assertSame(0, $code, $output);
        $runId = (int) $this->runs()[0]['id'];
        $this->share();
        $undo = $this->helper('undo', $runId, $stop);
        $undo->start();
        $this->waitFor($this->dir.'/ready', $undo);
        $undo->stop(0, 9);
        self::assertFalse($undo->isRunning());

        self::assertSame($left, $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame($panel, $this->onPanel(1));
        self::assertSame($row, $this->nlRow($service)['status'] ?? null);
        $this->staleClaims();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', null], [$this->moves()[0]['state'], $this->moves()[0]['next_attempt_at']]);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        $this->fake->loadState();
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertCount(1, $this->attachCalls(), 'one attach in all');
    }

    public function testAPassLeavesAKilledUndoToTheNextUndo(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', \App\Tests\Support\Fake3xui\Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $this->fake->clearScripts();
        $runId = (int) $this->runs()[0]['id'];
        $this->share();
        $undo = $this->helper('undo', $runId, 'before|GET panel/api/clients/get/tg_p1_1|1');
        $undo->start();
        $this->waitFor($this->dir.'/ready', $undo);
        $undo->stop(0, 9);
        $this->staleClaims();
        $this->fake->loadState();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertConsoleContains('an undo stopped here', $output, (string) $code);
        self::assertSame('attaching', $this->moves()[0]['state']);
        self::assertSame([], $this->fake->requests);
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null, 'still held');
    }

    /** A link reset between the detach and the write: nothing written, picked again, the row held meanwhile. */
    public function testALinkResetDuringTheStepIsDiscardedAndTheMoveIsPickedAgain(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->share()->plannedRemove();
        $runner = $this->helper('resume', $runId, 'after|GET panel/api/clients/get/tg_p1_1|2');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        $this->fake->loadState();
        $this->fake->clients['tg_p1_1'] = array_replace($this->fake->clients['tg_p1_1'], ['id' => self::NEW_UUID, 'subId' => self::NEW_SUB]);
        $this->fake->saveState();
        $reset = new Process([$this->php(), 'tests/Support/bin/reset-credentials.php', (string) $service->getId(), self::NEW_UUID, self::NEW_SUB, '0', $this->dir.'/locked'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $reset->run();
        self::assertSame('reset', self::assertSubprocessClean($reset, 'reset'));
        touch($this->dir.'/go');
        $runner->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $move = $this->moves()[0];
        self::assertSame(['done', 2, null], [$move['state'], (int) $move['attempts'], $move['skip_reason']], 'picked again; the first try sent the detach');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame([self::NEW_UUID, self::NEW_SUB], [$row['client_uuid'], $row['sub_id']]);
        self::assertStringContainsString(self::NEW_UUID, (string) $row['config_text'], 'the final snapshot belongs to the new credentials');
        self::assertStringNotContainsString('00000001-2222', (string) $row['config_text']);
        self::assertNull($this->nlRow($service));
        $this->fake->loadState();
        self::assertCount(1, $this->detachCalls());
    }

    /**
     * A Regenerate that read the panel before the detach and stores after the run: the known limit of 5.4. D's link stays
     * stored until the next refresh; the subscription URL is live, and nothing else of the service or the run is lost.
     */
    public function testARegenerateOverlappingTheRemoveLosesNothing(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->share()->plannedRemove();
        $regenerate = new Process([$this->php(), 'tests/Support/bin/regenerate-links.php', 'button', (string) $service->getId(), (string) $this->de->getId(), self::NEW_UUID, self::NEW_SUB, $this->dir.'/ready', $this->dir.'/go', $this->dir.'/probe'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $regenerate->start();
        $this->waitFor($this->dir.'/ready', $regenerate);

        $runner = $this->helper('resume', $runId);
        $runner->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $removed = $this->serviceRow((int) $service->getId());
        touch($this->dir.'/go');
        $regenerate->wait();
        self::assertSubprocessClean($regenerate, 'regenerate');

        $after = $this->serviceRow((int) $service->getId());
        self::assertSame($removed['remote_id'], $after['remote_id'], 'no lost update');
        self::assertNull($this->nlRow($service));
        self::assertSame('done', $this->moves()[0]['state']);

        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        $this->em->clear();
        self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($this->em->find($service::class, $service->getId()) ?? self::fail('service'), 'test');
        self::assertCount(1, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true), 'the next refresh drops D\'s link');
    }

    /** A service due for the timer's read: its row was never checked. */
    private function dueOnBoth(): \App\Entity\VpnService
    {
        $service = $this->onBoth(1);
        $this->em->getConnection()->update('vpn_service_inbound', ['checked_at' => null], ['service_id' => $service->getId()]);

        return $service;
    }

    private function assertStaysRemoved(\App\Entity\VpnService $service): void
    {
        self::assertNull($this->nlRow($service));
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        self::assertSame([], $this->attachCalls(), 'never attached again');
        $timer = $this->timer('-', (int) $service->getId());
        $timer->run();
        self::assertSame('attached=0 removed=0 alerts=0', self::lastLine(self::assertSubprocessClean($timer, 'timer')));
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
    }

    private static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return (string) end($lines);
    }

    /** One run of the attach timer (mode timer of inbound-move.php), stopped at $block. */
    private function timer(string $block, int $serviceId): Process
    {
        return new Process([$this->php(), 'tests/Support/bin/inbound-move.php', 'timer', (string) $serviceId, $this->stateFile, $this->timerDir, $block, '0', ''], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
    }

    private function retryAttach(int $serviceId, int $delayMs): Process
    {
        return new Process([$this->php(), 'tests/Support/bin/multi-inbound.php', 'retry-attach', (string) $serviceId, $this->stateFile, (string) $delayMs], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
    }

    private function staleClaims(): void
    {
        // Five minutes later (ServiceInboundAttacher::STALE_CLAIM): the claims of the dead process are taken over.
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL');
    }

    /** The in-memory fake (with the clients the fixtures added) becomes the shared state, used in this process too. */
    private function share(): self
    {
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();

        return $this;
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

    private function waitForRow(\App\Entity\VpnService $service, string $status, Process $process): void
    {
        $until = microtime(true) + 120;
        while ($status !== ($this->nlRow($service)['status'] ?? null)) {
            if (!$process->isRunning() || microtime(true) > $until) {
                self::fail('The row never became '.$status.': '.$process->getErrorOutput());
            }
            usleep(25_000);
        }
    }
}
