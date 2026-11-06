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
 * PR P11b, the two-process tests of docs/design/plan-inbound-add-remove.md 11.1 for kind=add, on MariaDB with real PHP
 * processes (tests/Support/bin/inbound-move.php, multi-inbound.php) and one fake 3x-ui whose state they share: "Retry
 * attach" on the same row in both orders, kill -9 at each row of 5.4 (and in the undo), a link reset and a Regenerate
 * during a step, and two runners on one run.
 */
#[Group('subprocess')]
final class InboundMoveAddParallelTest extends IntegrationTestCase
{
    use InboundAddFixtures;
    use ParallelStart;
    use SubprocessAssertions;

    private const NEW_UUID = '99999999-8888-4777-a666-555555555555';
    private const NEW_SUB = 'p11resetsub000000001';

    private string $stateFile;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdd();
        $this->dir = sys_get_temp_dir().'/amoobot-p11-'.bin2hex(random_bytes(4));
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

    public function testTwoRunnersOnOneRunAddOnce(): void
    {
        foreach (range(1, 4) as $n) {
            $this->goldService($n);
        }
        $runId = $this->share()->plannedAdd();

        $runners = [$this->helper('runner', $runId, '-', 200, $this->startAt()), $this->helper('runner', $runId, '-', 200, $this->startAt())];
        array_map(static fn (Process $p) => $p->start(), $runners);
        $this->releaseStart($runners);
        $outputs = array_map(function (Process $p): string { $p->wait(); return self::assertSubprocessClean($p, 'runner'); }, $runners);

        self::assertSame(4, self::consoleCount(implode("\n", $outputs), ': added: '), implode("\n---\n", $outputs));
        self::assertSame(array_fill(0, 4, ['done', 1]), array_map(static fn (array $m): array => [$m['state'], (int) $m['attempts']], $this->moves()));
        $this->fake->loadState();
        foreach (range(1, 4) as $n) {
            self::assertSame([1, 2], $this->onPanel($n));
            self::assertCount(1, array_keys($this->fake->requestLines(), 'POST panel/api/clients/tg_p1_'.$n.'/attach'), 'one attach per client');
        }
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'), 'finished once');
    }

    /** "Retry attach" while the run holds the row: it finds the claim, and calls nothing. */
    public function testRetryAttachFindsTheRowClaimedByTheRun(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed');
        $runId = $this->share()->plannedAdd();
        $runner = $this->helper('resume', $runId, 'after|GET panel/api/clients/get/tg_p1_1|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        self::assertSame('pending', $this->nlRow($service)['status'] ?? null, 'claimed as the attacher claims it');

        $retry = $this->retryAttach((int) $service->getId(), 0);
        $retry->run();

        self::assertSame('in_progress', self::assertSubprocessClean($retry, 'retry-attach'));
        touch($this->dir.'/go');
        $runner->wait();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')), 'no lock, no transaction during the panel step');
        $this->fake->loadState();
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertCount(1, $this->attachCalls(), 'one attach in all');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('done', $this->moves()[0]['state']);
    }

    /** The other order: the run finds the attacher's fresh claim and waits for the next pass. */
    public function testTheRunWaitsForARetryAttachInFlight(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed');
        $runId = $this->share()->plannedAdd();
        $retry = $this->retryAttach((int) $service->getId(), 3000);
        $retry->start();
        $this->waitForRow($service, 'pending', $retry);

        $runner = $this->helper('resume', $runId);
        $runner->run();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame(['planned', 0], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']], 'busy, not a skip');
        $retry->wait();
        self::assertSame('done', self::assertSubprocessClean($retry, 'retry-attach'));

        $again = $this->helper('resume', $runId);
        $again->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($again, 'again'));
        self::assertSame(['skipped', 'already_on_target'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        $this->fake->loadState();
        self::assertCount(1, $this->attachCalls(), 'one attach in all');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    /** Both at the same moment, whichever wins the claim: one attach, one row, no deadlock. */
    public function testTheRunAndRetryAttachAtTheSameMoment(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed');
        $runId = $this->share()->plannedAdd();
        $runner = $this->helper('resume', $runId, '-', 300, $this->startAt());
        $retry = new Process([$this->php(), 'tests/Support/bin/multi-inbound.php', 'retry-attach', (string) $service->getId(), $this->stateFile, '300'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $runner->start();
        $retry->start();
        $this->releaseStart([$runner]);
        $runner->wait();
        $retry->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertContains(self::assertSubprocessClean($retry, 'retry-attach'), ['done', 'in_progress']);
        $finish = $this->helper('resume', $runId);
        $finish->run();
        self::assertStringStartsWith('exit=', self::assertSubprocessClean($finish, 'finish'));
        $this->fake->loadState();
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertCount(1, $this->attachCalls(), 'one attach in all');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertCount(1, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]));
    }

    /** "Retry attach" of another inbound of the same client during the step: both land. */
    public function testRetryAttachOfAnotherInboundDuringTheStepKeepsBothEffects(): void
    {
        $service = $this->goldService(1);
        $remote = $this->fake->addInbound(['remark' => 'extra', 'protocol' => 'vless', 'port' => 2096]);
        $extra = $this->panelInbound($remote, 'EXTRA');
        $this->em->flush();
        $this->rowFor($service, 'failed', ['inbound_id' => $extra->getId(), 'remote_inbound_id' => (string) $remote]);
        $runId = $this->share()->plannedAdd();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        $retry = $this->retryAttach((int) $service->getId(), 0);
        $retry->run();
        touch($this->dir.'/go');
        $runner->wait();

        self::assertSame('done', self::assertSubprocessClean($retry, 'retry-attach'));
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $this->fake->loadState();
        $onPanel = $this->onPanel(1);
        sort($onPanel);
        self::assertSame([1, 2, $remote], $onPanel);
        self::assertSame(['attached', 'attached'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$service->getId()])));
    }

    /** @return iterable<string, array{string, string, list<int>}> where the runner dies, the move left, and the client's inbounds then */
    public static function killPoints(): iterable
    {
        yield 'claimed, before the read' => ['before|GET panel/api/clients/get/tg_p1_1|1', 'attaching', [1]];
        yield 'attached, before the write' => ['after|POST panel/api/clients/tg_p1_1/attach|1', 'attaching', [1, 2]];
        yield 'written, before the links' => ['before|GET panel/api/clients/subLinks/.*|1', 'switched', [1, 2]];
    }

    /** kill -9 at each add row of 5.4: the next pass finishes it, with exactly one attach in all. */
    #[DataProvider('killPoints')]
    public function testAKilledRunnerIsFinishedByTheNextPass(string $stop, string $left, array $panel): void
    {
        $service = $this->goldService(1);
        $before = $this->identityOf($service, 1);
        $runId = $this->share()->plannedAdd();
        $runner = $this->helper('resume', $runId, $stop);
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        self::assertFalse($runner->isRunning());

        self::assertSame($left, $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame($panel, $this->onPanel(1));
        self::assertSame('switched' === $left, null !== $this->nlRow($service), 'the row is written only after the panel confirmed');
        $this->staleClaims();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->moves()[0]['skip_reason'], 'this run put it there: undo can take it off');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        $this->fake->loadState();
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertCount(1, $this->attachCalls(), 'exactly one attach in all');
        self::assertSame($before, $this->identityOf($service, 1));
        self::assertNull($this->serviceRow((int) $service->getId())['inbound_changed_at']);
    }

    /** A run killed holding a failed row: the row is taken back by the takeover, and the timer never sees it as lost. */
    public function testAKilledRunnerHoldingARowLeavesItToTheTakeover(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed');
        $runId = $this->share()->plannedAdd();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        self::assertSame('pending', $this->nlRow($service)['status'] ?? null);
        $this->staleClaims();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertCount(1, $this->attachCalls());
    }

    /** @return iterable<string, array{string, string, list<int>, bool}> where the undo dies, the move left, the client's inbounds, whether the row is left */
    public static function undoKillPoints(): iterable
    {
        yield 'claimed, before the read' => ['before|GET panel/api/clients/get/tg_p1_1|1', 'detaching', [1, 2], true];
        yield 'detached, before the write' => ['after|POST panel/api/clients/tg_p1_1/detach|1', 'detaching', [1], true];
        yield 'written, before the links' => ['before|GET panel/api/clients/subLinks/.*|1', 'undone', [1], false];
    }

    /** kill -9 in the undo: --undo again finishes it, one detach in all, and the row is never left attached meanwhile. */
    #[DataProvider('undoKillPoints')]
    public function testAKilledUndoIsFinishedByTheNextUndo(string $stop, string $left, array $panel, bool $rowLeft): void
    {
        $service = $this->goldService(1);
        [$code, $output] = $this->add(['--apply' => true]);
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
        self::assertSame($rowLeft ? 'detached' : null, $this->nlRow($service)['status'] ?? null, 'held: the timer cannot attach it again');
        $this->staleClaims();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', null], [$this->moves()[0]['state'], $this->moves()[0]['next_attempt_at']]);
        self::assertNull($this->nlRow($service));
        $this->fake->loadState();
        self::assertSame([1], $this->onPanel(1));
        $row = $this->serviceRow((int) $service->getId());
        self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at'], 'links refreshed');
        self::assertLessThanOrEqual(1, count(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/detach'))));
    }

    /** A link reset between the panel step and the write: nothing written, picked again, links of the new credentials. */
    public function testALinkResetDuringTheStepIsDiscardedAndTheMoveIsPickedAgain(): void
    {
        $service = $this->goldService(1);
        $runId = $this->share()->plannedAdd();
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
        self::assertSame(['done', 2, null], [$move['state'], (int) $move['attempts'], $move['skip_reason']], 'picked again; the first try sent the attach');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame([self::NEW_UUID, self::NEW_SUB], [$row['client_uuid'], $row['sub_id']]);
        self::assertStringContainsString(self::NEW_UUID, (string) $row['config_text'], 'the final snapshot belongs to the new credentials');
        self::assertStringNotContainsString('00000001-2222', (string) $row['config_text']);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        $this->fake->loadState();
        self::assertCount(1, $this->attachCalls());
    }

    /**
     * A Regenerate that read the panel before the attach and stores after the run: an add writes no identity or stamp that
     * would discard it, so its older snapshot is stored. That is the known limit of 5.4 the other way round: those links
     * are true, only without C's, and the next refresh brings it. Nothing else of the service or the run is lost.
     */
    public function testARegenerateOverlappingTheAddLosesNothing(): void
    {
        $service = $this->goldService(1);
        $runId = $this->share()->plannedAdd();
        $regenerate = new Process([$this->php(), 'tests/Support/bin/regenerate-links.php', 'button', (string) $service->getId(), (string) $this->de->getId(), self::NEW_UUID, self::NEW_SUB, $this->dir.'/ready', $this->dir.'/go', $this->dir.'/probe'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $regenerate->start();
        $this->waitFor($this->dir.'/ready', $regenerate);

        $runner = $this->helper('resume', $runId);
        $runner->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $added = $this->serviceRow((int) $service->getId());
        touch($this->dir.'/go');
        $regenerate->wait();
        self::assertSubprocessClean($regenerate, 'regenerate');

        $after = $this->serviceRow((int) $service->getId());
        self::assertSame($added['remote_id'], $after['remote_id'], 'no lost update');
        self::assertNull($after['inbound_changed_at'], 'no guard: the stored links stay visible');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('done', $this->moves()[0]['state']);

        $this->fake->loadState();
        $this->em->clear();
        self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($this->em->find($service::class, $service->getId()) ?? self::fail('service'), 'test');
        self::assertCount(2, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true), 'the next refresh brings C\'s link');
    }

    private function retryAttach(int $serviceId, int $delayMs): Process
    {
        return new Process([$this->php(), 'tests/Support/bin/multi-inbound.php', 'retry-attach', (string) $serviceId, $this->stateFile, (string) $delayMs], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
    }

    private function staleClaims(): void
    {
        // Five minutes later (ServiceInboundAttacher::STALE_CLAIM): the claims of the dead process are taken over.
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL');
        $db->executeStatement("UPDATE vpn_service_inbound SET updated_at = DATE_SUB(updated_at, INTERVAL 6 MINUTE) WHERE status = 'pending'");
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
