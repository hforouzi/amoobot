<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR P6, the two-process tests of docs/design/plan-inbound-change.md 8.2 for the retire step (4.3 step 5, the last row
 * of the 5.4 table), on MariaDB with real PHP processes (tests/Support/bin/inbound-move.php) and one fake 3x-ui whose
 * state they share:
 * - P-6: the runner killed (kill -9) after the attach check, after the detach and before the snapshot: the next pass
 *   finishes, with no duplicate call that changes state;
 * - races on a service being retired: suspend, renew (updateClient), a link reset (rotateCredentials), Regenerate.
 * Each stop also records whether the service row was locked and whether a transaction was open: never, for the runner.
 */
#[Group('subprocess')]
final class InboundMoveRetireParallelTest extends IntegrationTestCase
{
    use InboundMoveFixtures;
    use ParallelStart;
    use SubprocessAssertions;

    private const NEW_UUID = '99999999-8888-4777-a666-555555555555';
    private const NEW_SUB = 'p6resetsub0000000001';

    private string $stateFile;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->dir = sys_get_temp_dir().'/amoobot-p6-'.bin2hex(random_bytes(4));
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

    /** @return iterable<string, array{string, string, list<int>}> where the runner is killed, the move left behind, A and B on the panel then */
    public static function killPoints(): iterable
    {
        yield 'after the attach check, before the detach' => ['after|GET panel/api/clients/get/tg_p1_1|1', 'detaching', [1, 2]];
        yield 'after the detach, before its read' => ['after|POST panel/api/clients/tg_p1_1/detach|1', 'detaching', [2]];
        yield 'before the snapshot' => ['before|GET panel/api/clients/subLinks/.*|1', 'done', [2]];
    }

    /** P-6 (retire): killed in each step: the next pass finishes it, with exactly one detach call in all. */
    #[DataProvider('killPoints')]
    public function testAKilledRetireIsFinishedByTheNextPassWithoutASecondDetach(string $stop, string $left, array $onPanel): void
    {
        $service = $this->movableService(1);
        $runId = $this->movedRun();
        $runner = $this->helper('resume', $runId, $stop);
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        self::assertFalse($runner->isRunning());

        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')), 'no lock, no transaction during the panel step');
        self::assertSame($left, $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame($onPanel, $this->fake->clients['tg_p1_1']['inboundIds']);
        // Five minutes later (ServiceInboundAttacher::STALE_CLAIM): the claim of the dead process is taken over.
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL');
        $this->em->clear();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNotNull($this->moves()[0]['detached_at']);
        self::assertSame('done', $this->runs()[0]['status']);
        $this->fake->loadState();
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertCount(1, $this->detachCalls(), 'exactly one detach in all: never a second one');
        self::assertSame([], $this->attachCalls(), 'no attach');
        $row = $this->serviceRow((int) $service->getId());
        self::assertNotNull($row['last_access_info_synced_at'], 'links refreshed after the retire');
        self::assertCount(1, json_decode((string) $row['config_links'], true), 'B only');
    }

    /** P-6: killed after B was attached again: the next pass finds B and detaches A, one attach and one detach in all. */
    public function testAKillAfterTheReattachOfBIsFinishedByTheNextPass(): void
    {
        $this->movableService(1);
        $runId = $this->movedRun([1]);
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/attach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $runner->stop(0, 9);
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET claimed_at = DATE_SUB(claimed_at, INTERVAL 6 MINUTE) WHERE claimed_at IS NOT NULL');
        $this->em->clear();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        $this->fake->loadState();
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertCount(1, $this->attachCalls());
        self::assertCount(1, $this->detachCalls());
        self::assertSame('done', $this->moves()[0]['state']);
    }

    /** Two runners on one due retire: one detach; the loser finds nothing to claim. */
    public function testTwoRunnersRetireOnce(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 3));
        $runId = $this->movedRun();

        $runners = [$this->helper('resume', $runId, '-', 200, $this->startAt()), $this->helper('resume', $runId, '-', 200, $this->startAt())];
        array_map(static fn (Process $p) => $p->start(), $runners);
        $this->releaseStart($runners);
        foreach ($runners as $runner) {
            $runner->wait();
            self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        }

        self::assertSame(['done', 'done', 'done'], array_column($this->moves(), 'state'));
        $this->fake->loadState();
        self::assertCount(3, $this->detachCalls(), 'one detach per client');
        self::assertSame('done', $this->runs()[0]['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function clientUpdates(): iterable
    {
        yield 'suspend' => ['suspend'];
        yield 'renew' => ['renew'];
    }

    /** A suspend or a renewal (updateClient) while the runner waits between the detach and its read: both effects stay. */
    #[DataProvider('clientUpdates')]
    public function testAClientUpdateDuringTheRetireKeepsBothEffects(string $mode): void
    {
        $service = $this->movableService(1);
        $runId = $this->movedRun();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);
        $update = $this->helper($mode, (int) $service->getId());
        $update->run();
        touch($this->dir.'/go');
        $runner->wait();

        self::assertContains(self::assertSubprocessClean($update, $mode), ['DONE', 'renewed']);
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame('row-free no-tx', trim((string) file_get_contents($this->dir.'/probe')));
        $this->fake->loadState();
        $client = $this->fake->clients['tg_p1_1'];
        self::assertSame([2], $client['inboundIds'], 'the update changed no membership [unverified on a real panel: U3]');
        self::assertSame('done', $this->moves()[0]['state']);
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        if ('suspend' === $mode) {
            self::assertSame([false, 'suspended'], [$client['enable'], $row['status']]);
        } else {
            self::assertGreaterThan((time() + 29 * 86400) * 1000, (int) $client['expiryTime'], 'renewed on the panel');
        }
    }

    /** @return iterable<string, array{string}> where the runner waits while the link reset runs */
    public static function resetPoints(): iterable
    {
        yield 'before the attach check' => ['before|GET panel/api/clients/get/tg_p1_1|1'];
        yield 'after the attach check, before the detach' => ['after|GET panel/api/clients/get/tg_p1_1|1'];
    }

    /** A link reset during the retire: nothing lost, one detach in all, the final snapshot belongs to the new credentials. */
    #[DataProvider('resetPoints')]
    public function testALinkResetDuringTheRetire(string $stop): void
    {
        $service = $this->movableService(1);
        $runId = $this->movedRun();
        $runner = $this->helper('resume', $runId, $stop);
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

        $output = self::assertSubprocessClean($runner, 'runner');
        $this->em->clear();
        if (!str_starts_with($output, 'exit=0')) {
            // The read after the detach no longer knew the client: failed, not confirmed. The next try reads first.
            $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00' WHERE state = 'failed'");
            [$code, $again] = $this->command(['--resume' => (string) $runId]);
            self::assertSame(0, $code, $again);
        }
        self::assertSame('done', $this->moves()[0]['state'], $output);
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame([(string) $this->nl->getId(), self::NEW_UUID, self::NEW_SUB], [(string) $row['inbound_id'], $row['client_uuid'], $row['sub_id']]);
        self::assertStringContainsString(self::NEW_UUID, (string) $row['config_text'], 'the final snapshot belongs to the new credentials');
        self::assertStringNotContainsString('00000001-2222', (string) $row['config_text']);
        $this->fake->loadState();
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertCount(1, $this->detachCalls(), 'one detach in all');
    }

    /** Regenerate between the detach and the runner's refresh: the runner's snapshot, read after the detach, is the last. */
    public function testARegenerateDuringTheRetireEndsWithTheSnapshotOfTheNewInbound(): void
    {
        $service = $this->movableService(1);
        $runId = $this->movedRun();
        $runner = $this->helper('resume', $runId, 'after|POST panel/api/clients/tg_p1_1/detach|1');
        $runner->start();
        $this->waitFor($this->dir.'/ready', $runner);

        touch($this->dir.'/regenerate-go');
        $regenerate = $this->regenerate((int) $service->getId());
        $regenerate->run();
        self::assertSubprocessClean($regenerate, 'regenerate');
        touch($this->dir.'/go');
        $runner->wait();

        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        self::assertSame('done', $this->moves()[0]['state']);
        $row = $this->serviceRow((int) $service->getId());
        $links = json_decode((string) $row['config_links'], true);
        self::assertCount(1, $links, 'B only');
        self::assertStringStartsWith('vmess://', (string) $links[0]);
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
    }

    /**
     * The other order, a KNOWN LIMIT (PanelLinkSnapshot's identity check does not see a retire: the remote id does not
     * change): a Regenerate that read the links before the detach and stores them after the runner's refresh leaves
     * A's link in the stored snapshot, until the next refresh. No state is lost: the move, the inbound and the remote id
     * stay, and the subscription URL (live) is right.
     */
    public function testAStaleRegenerateCannotUndoTheRetire(): void
    {
        $service = $this->movableService(1);
        $runId = $this->movedRun();
        $regenerate = $this->regenerate((int) $service->getId());
        $regenerate->start();
        $this->waitFor($this->dir.'/regenerate-ready', $regenerate);

        $runner = $this->helper('resume', $runId);
        $runner->run();
        self::assertStringStartsWith('exit=0', self::assertSubprocessClean($runner, 'runner'));
        $retired = $this->serviceRow((int) $service->getId());
        touch($this->dir.'/regenerate-go');
        $regenerate->wait();
        self::assertSubprocessClean($regenerate, 'regenerate');

        $after = $this->serviceRow((int) $service->getId());
        self::assertSame([$retired['inbound_id'], $retired['remote_id']], [$after['inbound_id'], $after['remote_id']], 'no lost update');
        self::assertSame('done', $this->moves()[0]['state']);
        $this->fake->loadState();
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
    }

    /**
     * Services moved by a first pass in this process (grace period over), with the fake's state shared from here on.
     *
     * @param list<int>|null $handEdit the first client's inbounds on the panel after the move (a hand edit)
     */
    private function movedRun(?array $handEdit = null): int
    {
        $this->fake->stateFile = null;
        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(0, $code, $output);
        self::assertContains('switched', array_column($this->moves(), 'state'));
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE state = 'switched'");
        if (null !== $handEdit) {
            $this->fake->clients['tg_p1_1']['inboundIds'] = $handEdit;
        }
        $this->fake->requests = [];
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();

        return (int) $this->runs()[0]['id'];
    }

    /** @return list<string> */
    private function detachCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/detach')));
    }

    private function regenerate(int $serviceId): Process
    {
        return new Process([$this->php(), 'tests/Support/bin/regenerate-links.php', 'button', (string) $serviceId, (string) $this->nl->getId(), self::NEW_UUID, self::NEW_SUB, $this->dir.'/regenerate-ready', $this->dir.'/regenerate-go', $this->dir.'/regenerate-probe'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
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
