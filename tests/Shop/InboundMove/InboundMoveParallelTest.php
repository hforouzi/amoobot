<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnInbound;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P4, the two-process tests P-1 ... P-6 of docs/design/plan-inbound-change.md 8.2, on MariaDB with real PHP processes
 * (tests/Support/bin/inbound-move.php) and one fake 3x-ui whose state they share. A helper can stop at an exact panel
 * request (before or after it) and is then either released or killed (5.4); each stop also records whether the service
 * row was locked and whether a transaction was open at that moment: never, for the runner (5.3).
 */
#[Group('subprocess')]
final class InboundMoveParallelTest extends IntegrationTestCase
{
    use InboundMoveFixtures;
    use ParallelStart;
    use SubprocessAssertions;

    private const NEW_UUID = '99999999-8888-4777-a666-555555555555';
    private const NEW_SUB = 'p4resetsub0000000001';

    private string $stateFile;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->dir = sys_get_temp_dir().'/amoobot-p4-'.bin2hex(random_bytes(4));
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

    /** P-1: two runners on the same run: each service moved once, one attach per client; the loser finds nothing. */
    public function testTwoRunnersOnOneRunMoveEachServiceOnce(): void
    {
        $services = array_map(fn (int $n) => $this->movableService($n), range(1, 4));
        $runId = $this->share()->createRun();

        $runners = [$this->helper('resume', $runId, '-', 200, $this->startAt()), $this->helper('resume', $runId, '-', 200, $this->startAt())];
        array_map(static fn (Process $p) => $p->start(), $runners);
        $this->releaseStart($runners);
        $outputs = array_map(function (Process $p): string { $p->wait(); return self::assertSubprocessClean($p, 'runner'); }, $runners);

        foreach ($outputs as $output) {
            self::assertStringStartsWith('exit=0', $output);
        }
        self::assertSame(4, self::consoleCount(implode("\n", $outputs), ': switched: '), 'four switches in all: '.implode("\n---\n", $outputs));
        self::assertSame(array_fill(0, 4, ['switched', 1]), array_map(static fn (array $m): array => [$m['state'], (int) $m['attempts']], $this->moves()));
        $this->fake->loadState();
        foreach ($services as $n => $service) {
            self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
            self::assertSame([1, 2], $this->fake->clients['tg_p1_'.($n + 1)]['inboundIds']);
            self::assertCount(1, array_keys($this->fake->requestLines(), 'POST panel/api/clients/tg_p1_'.($n + 1).'/attach'), 'one attach per client');
        }
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'), 'finished once');
        self::assertSame('done', $this->runs()[0]['status']);
    }

    /** P-2: the runner and "Retry attach" on the same multi-inbound service: both effects, one attach each, no deadlock. */
    public function testTheRunnerAndRetryAttachOnOneMultiInboundService(): void
    {
        $this->setting(MultiInboundSettings::ENABLED, 'true', 'boolean');
        $remote = $this->fake->addInbound(['remark' => 'extra', 'protocol' => 'vless', 'port' => 2096]);
        $extra = (new VpnInbound())->setPanel($this->managed($this->modernPanel))->setRemoteInboundId((string) $remote)->setTitle('EXTRA')->setProtocol('vless');
        $this->em->persist($extra);
        $this->em->flush();
        $service = $this->planService($this->gold, $this->de, n: 1);
        // Sold while EXTRA was missing on the panel: its row failed; a retry is due.
        $this->em->getConnection()->insert('vpn_service_inbound', ['service_id' => $service->getId(), 'inbound_id' => $extra->getId(), 'remote_inbound_id' => (string) $remote, 'status' => 'failed', 'attempts' => 1, 'last_error' => 'inbound not found', 'updated_at' => '2026-01-01 00:00:00', 'reattach_count' => 0]);
        $this->sellOn($this->gold, $this->nl);
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->de), $this->managed($extra)]);
        $this->em->flush();
        $runId = $this->share()->createRun();

        $runner = $this->helper('resume', $runId, '-', 300, $this->startAt());
        $retry = new Process([$this->php(), 'tests/Support/bin/multi-inbound.php', 'retry-attach', (string) $service->getId(), $this->stateFile, '300'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $runner->start();
        $retry->start();
        $this->releaseStart([$runner]);
        $runner->wait();
        $retry->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertContains(self::assertSubprocessClean($retry, 'retry-attach'), ['done', 'in_progress']);
        $this->fake->loadState();
        $onPanel = $this->fake->clients['tg_p1_1']['inboundIds'];
        sort($onPanel);
        self::assertSame([1, 2, $remote], $onPanel, 'both attaches on the panel (in the order the race gave)');
        $attached = [];
        foreach ($this->fake->requests as $request) {
            if ('POST' === $request['method'] && 'panel/api/clients/tg_p1_1/attach' === $request['path']) {
                array_push($attached, ...array_map('intval', (array) ($request['body']['inboundIds'] ?? [])));
            }
        }
        sort($attached);
        self::assertSame([2, $remote], $attached, 'NL-2 by the runner, EXTRA by the retry: each inbound attached once');
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT inbound_id, status FROM vpn_service_inbound WHERE service_id = ? ORDER BY inbound_id', [$service->getId()]);
        self::assertSame([(int) $this->de->getId() => 'attached', (int) $extra->getId() => 'attached'], $rows, 'no row for the primary NL-2');
    }

    /** P-3: a link reset between the panel step and the write: nothing written, re-picked, links of the new credentials. */
    public function testALinkResetDuringTheMoveIsDiscardedAndTheMoveIsPickedAgain(): void
    {
        $service = $this->movableService(1);
        $runId = $this->share()->createRun();
        $runner = $this->helper('resume', $runId, 'after|GET panel/api/clients/get/tg_p1_1|2');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        // The reset: its panel step (new UUID and subId on the panel), then its database step.
        $this->fake->loadState();
        $this->fake->clients['tg_p1_1'] = array_replace($this->fake->clients['tg_p1_1'], ['id' => self::NEW_UUID, 'subId' => self::NEW_SUB]);
        $this->fake->saveState();
        $reset = new Process([$this->php(), 'tests/Support/bin/reset-credentials.php', (string) $service->getId(), self::NEW_UUID, self::NEW_SUB, '0', $this->dir.'/locked'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $reset->run();
        self::assertSame('reset', self::assertSubprocessClean($reset, 'reset'));
        touch($this->dir.'/go');
        $runner->wait();

        $output = self::assertSubprocessClean($runner, 'runner');
        self::assertStringStartsWith('exit=0', $output);
        self::assertConsoleContains(': switched: already on the target', $output, 're-picked with the new credentials');
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')), 'no lock, no transaction during the panel step');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame([(string) $this->nl->getId(), self::NEW_UUID, self::NEW_SUB], [(string) $row['inbound_id'], $row['client_uuid'], $row['sub_id']]);
        self::assertStringContainsString(self::NEW_UUID, (string) $row['remote_id']);
        self::assertStringContainsString(self::NEW_UUID, (string) $row['config_text'], 'the final snapshot belongs to the new credentials');
        self::assertStringNotContainsString('00000001-2222', (string) $row['config_text']);
        self::assertSame(['switched', 2], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']]);
        $this->fake->loadState();
        self::assertCount(1, $this->attachCalls(), 'one attach in all');
    }

    /** P-4: a Regenerate that read the panel before the switch and writes after it: the switch's snapshot stays. */
    public function testARegenerateOverlappingTheMoveLosesNothing(): void
    {
        $service = $this->movableService(1);
        $runId = $this->share()->createRun();
        $regenerate = new Process([$this->php(), 'tests/Support/bin/regenerate-links.php', 'button', (string) $service->getId(), (string) $this->de->getId(), self::NEW_UUID, self::NEW_SUB, $this->dir.'/ready', $this->dir.'/go', $this->dir.'/probe'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $regenerate->start();
        $this->waitFor($this->dir.'/ready', $regenerate);

        $runner = $this->helper('resume', $runId);
        $runner->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $switched = $this->serviceRow((int) $service->getId());
        touch($this->dir.'/go');
        $regenerate->wait();
        self::assertSubprocessClean($regenerate, 'regenerate');

        $after = $this->serviceRow((int) $service->getId());
        self::assertSame([(string) $this->nl->getId(), $switched['remote_id']], [(string) $after['inbound_id'], $after['remote_id']], 'no lost update of the switch');
        self::assertSame($switched['config_links'], $after['config_links'], 'the snapshot from after the switch stays');
        self::assertSame('switched', $this->moves()[0]['state']);
    }

    /** @return iterable<string, array{string}> who stops in the middle: the suspend after its read, or the runner after its attach */
    public static function suspendOrders(): iterable
    {
        yield 'the suspend read before the move' => ['suspend'];
        yield 'the suspend runs between attach and write' => ['runner'];
    }

    /** P-5: suspend (updateClient) and the move at the same time: the client is disabled and on both inbounds. */
    #[DataProvider('suspendOrders')]
    public function testASuspendDuringTheMoveKeepsBothEffects(string $stopped): void
    {
        $service = $this->movableService(1);
        $runId = $this->share()->createRun();
        if ('suspend' === $stopped) {
            $first = $this->helper('suspend', (int) $service->getId(), 'after|GET panel/api/clients/get/tg_p1_1|1');
            $second = $this->helper('resume', $runId);
        } else {
            $first = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
            $second = $this->helper('suspend', (int) $service->getId());
        }
        $first->start();
        $this->waitFor($this->dir.'/ready', $first);
        $second->run();
        touch($this->dir.'/go');
        $first->wait();

        $outputs = [self::assertSubprocessClean($first, 'first'), self::assertSubprocessClean($second, 'second')];
        self::assertContains('DONE', $outputs);
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')));
        $this->fake->loadState();
        $client = $this->fake->clients['tg_p1_1'];
        self::assertSame([false, [1, 2]], [$client['enable'], $client['inboundIds']], 'disabled, and on both inbounds');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame(['suspended', (string) $this->nl->getId()], [$row['status'], (string) $row['inbound_id']]);
        self::assertSame('switched', $this->moves()[0]['state']);
    }

    /** P-5: a renewal (updateClient with expiry and limit) while the runner waits between attach and write. */
    public function testARenewalDuringTheMoveKeepsBothEffects(): void
    {
        $service = $this->movableService(1);
        $runId = $this->share()->createRun();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $renew = $this->helper('renew', (int) $service->getId());
        $renew->run();
        touch($this->dir.'/go');
        $runner->wait();

        self::assertSame('renewed', self::assertSubprocessClean($renew, 'renew'));
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $this->fake->loadState();
        $client = $this->fake->clients['tg_p1_1'];
        self::assertSame([1, 2], $client['inboundIds']);
        self::assertGreaterThan((time() + 29 * 86400) * 1000, (int) $client['expiryTime'], 'renewed on the panel');
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), new \DateTimeImmutable((string) $row['expires_at']), 'renewed in the database');
    }

    /** @return iterable<string, array{string, bool}> where the runner is killed, and whether its claim is left behind */
    public static function killPoints(): iterable
    {
        yield 'claimed, before the panel read' => ['before|GET panel/api/clients/get/tg_p1_1|1', true];
        yield 'attached, before the write' => ['after|POST panel/api/clients/tg_p1_1/attach|1', true];
        yield 'written, before the links' => ['before|GET panel/api/clients/subLinks/.*|1', false];
    }

    /** P-6 (phase 1): killed in each step of 4.3: the next pass finishes it, with at most one attach call in all. */
    #[DataProvider('killPoints')]
    public function testAKilledRunnerIsFinishedByTheNextPassWithoutASecondAttach(string $stop, bool $claimLeft): void
    {
        $service = $this->movableService(1);
        $runId = $this->share()->createRun();
        $runner = $this->helper('resume', $runId, $stop);
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        self::assertFalse($runner->isRunning());

        $move = $this->moves()[0];
        self::assertSame($claimLeft ? 'attaching' : 'switched', $move['state']);
        // Five minutes later (ServiceInboundAttacher::STALE_CLAIM): the claim of the dead process is taken over.
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL");
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('switched', $this->moves()[0]['state']);
        self::assertSame('done', $this->runs()[0]['status']);
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at'], 'links refreshed after the switch');
        $this->fake->loadState();
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds']);
        // Killed before its read: the next pass sends the attach; killed after it: the next pass finds the client on B.
        self::assertCount(1, $this->attachCalls(), 'exactly one attach in all: never a second one');
    }

    /** The in-memory fake (with the clients the fixtures added) becomes the shared state, used in this process too. */
    private function share(): self
    {
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();

        return $this;
    }

    private function createRun(): int
    {
        $this->em->clear();
        // keep-old: these are the phase 1 races (P4); the retire step has its own (InboundMoveRetireParallelTest, P6).
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($this->gold), new InboundMoveScope(), null, true);
        self::assertIsArray($created, is_string($created) ? $created : '');

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
