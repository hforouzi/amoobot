<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Admin\Application\InboundMoveActions;
use App\Entity\InboundMoveRun;
use App\Entity\VpnInbound;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveOverview;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Application\InboundMoveScope;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;

/**
 * PR P11a (docs/design/plan-inbound-add-remove.md 3.3, 13): the preparation for kind=add and kind=remove, with neither
 * reachable yet. A run row of such a kind stays inert: no pass, control, page or dry run takes it, and it is never due.
 * The kind-aware rules are pinned here on the rows they read, for P11b and P11c to open. P11b and P11c opened add and
 * remove for the command line and the scheduled task, so the "inert" tests below now pin a kind nobody built, and the web
 * stays closed to both.
 */
final class InboundMoveOtherKindsTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    /** @return iterable<string, array{string}> */
    public static function otherKinds(): iterable
    {
        yield 'add' => [InboundMoveRun::KIND_ADD];
        yield 'remove' => [InboundMoveRun::KIND_REMOVE];
    }

    /** @return iterable<string, array{string}> */
    public static function closedKinds(): iterable
    {
        yield 'a kind not built' => ['replace'];
    }

    #[DataProvider('closedKinds')]
    public function testARunOfAnotherKindIsNeverProcessedNorShownAsDue(string $kind): void
    {
        $runId = $this->planned($kind);
        $before = $this->rows($runId);
        $runner = self::getContainer()->get(InboundMoveRunner::class);
        $scheduler = self::getContainer()->get(InboundMoveScheduler::class);

        self::assertSame(0, $runner->dueCount($runId));
        $dryRun = $scheduler->run(dryRun: true);
        self::assertSame([0, 0], [$dryRun->runs, $dryRun->due]);
        self::assertSame(0, $scheduler->run()->runs);
        self::assertSame(InboundMovePass::NOT_OPEN, $runner->process($runId)->outcome);
        self::assertSame(InboundMovePass::NOT_OPEN, $runner->undo($runId)->outcome);

        self::assertSame($before, $this->rows($runId));
        self::assertSame([], $this->panelWrites());
    }

    #[DataProvider('closedKinds')]
    public function testNoControlOrPageTakesARunOfAnotherKind(string $kind): void
    {
        $runId = $this->planned($kind);
        $before = $this->rows($runId);
        $controls = self::getContainer()->get(InboundMoveControls::class);
        $plan = (int) $this->gold->getId();

        self::assertFalse($controls->pause($runId)['ok']);
        self::assertFalse($controls->resume($runId, true)['ok']);
        self::assertFalse($controls->cancel($runId)['ok']);
        self::assertFalse($controls->retryFailed($runId, true)['ok']);
        self::assertFalse($controls->retireNow($runId)['ok']);
        self::assertNull(self::getContainer()->get(InboundMoveActions::class)->planOfRun($runId));
        self::assertNull(self::getContainer()->get(InboundMoveOverview::class)->planRun($plan, null));

        self::assertSame($before, $this->rows($runId));
    }

    #[DataProvider('otherKinds')]
    public function testNoPageTakesARunOfAnotherKind(string $kind): void
    {
        $runId = $this->planned($kind);

        self::assertNull(self::getContainer()->get(InboundMoveActions::class)->planOfRun($runId));
        self::assertNull(self::getContainer()->get(InboundMoveOverview::class)->planRun((int) $this->gold->getId(), null));
    }

    public function testAPlannedMoveIsDueOnceItsDetachAfterHasPassed(): void
    {
        $runId = $this->planned(InboundMoveRun::KIND_PRIMARY);
        $runner = self::getContainer()->get(InboundMoveRunner::class);
        self::assertSame(1, $runner->dueCount($runId), 'no detach_after, as every primary move');

        $this->detachAfter($runId, '+1 hour');
        self::assertSame(0, $runner->dueCount($runId));

        $this->detachAfter($runId, '-1 minute');
        self::assertSame(1, $runner->dueCount($runId));
    }

    public function testRetireNowMakesAWaitingPlannedMoveDue(): void
    {
        $runId = $this->planned(InboundMoveRun::KIND_PRIMARY);
        $this->detachAfter($runId, '+1 hour');

        self::assertTrue(self::getContainer()->get(InboundMoveControls::class)->retireNow($runId)['ok']);

        self::assertSame(1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));
    }

    public function testASwitchedMoveWaitsForItsLinksOnlyInAnAddRun(): void
    {
        $runId = $this->planned(InboundMoveRun::KIND_PRIMARY);
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE service_inbound_move SET state = 'switched' WHERE run_id = ?", [$runId]);
        $due = fn (): int => (int) $db->fetchOne('SELECT COUNT(*) FROM service_inbound_move m WHERE m.run_id = ? AND '.InboundMoveRunner::ADD_LINKS_DUE_SQL, [$runId]);

        self::assertSame(0, $due(), 'a primary keep-old move is finished');
        $this->kind($runId, InboundMoveRun::KIND_ADD);
        self::assertSame(1, $due());
    }

    /**
     * @return iterable<string, array{string, array{int, int}}>
     */
    public static function detachingCounts(): iterable
    {
        yield 'primary: phase 1 is finished' => [InboundMoveRun::KIND_PRIMARY, [1, 0]];
        yield 'add: as primary' => [InboundMoveRun::KIND_ADD, [1, 0]];
        yield 'remove: still to come' => [InboundMoveRun::KIND_REMOVE, [0, 1]];
    }

    /**
     * @param array{int, int} $doneRemaining
     */
    #[DataProvider('detachingCounts')]
    public function testADetachingMoveCountsByKind(string $kind, array $doneRemaining): void
    {
        $runId = $this->planned($kind);
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE service_inbound_move SET state = 'detaching' WHERE run_id = ?", [$runId]);

        self::getContainer()->get(InboundMovePlanner::class)->refreshCounts($runId);

        self::assertSame($doneRemaining, array_map('intval', array_values((array) $db->fetchAssociative('SELECT done_count, remaining_count FROM inbound_move_run WHERE id = ?', [$runId]))));
    }

    public function testThePlanChangedRulePerKind(): void
    {
        self::assertFalse(InboundMoveOverview::planChanged(InboundMoveRun::KIND_PRIMARY, 2, 2, []));
        self::assertTrue(InboundMoveOverview::planChanged(InboundMoveRun::KIND_PRIMARY, 2, 1, []));
        self::assertFalse(InboundMoveOverview::planChanged(InboundMoveRun::KIND_ADD, 3, 1, [3]));
        self::assertTrue(InboundMoveOverview::planChanged(InboundMoveRun::KIND_ADD, 3, 1, []));
        self::assertTrue(InboundMoveOverview::planChanged(InboundMoveRun::KIND_ADD, null, 1, []), 'target deleted');
        self::assertFalse(InboundMoveOverview::planChanged(InboundMoveRun::KIND_REMOVE, 3, 1, [4]));
        self::assertTrue(InboundMoveOverview::planChanged(InboundMoveRun::KIND_REMOVE, 3, 3, []), 'it is the primary now');
        self::assertTrue(InboundMoveOverview::planChanged(InboundMoveRun::KIND_REMOVE, 3, 1, [3]), 'back in the plan');
    }

    public function testWhatUndoCanStillPutBackPerKind(): void
    {
        $runId = $this->planned(InboundMoveRun::KIND_PRIMARY);
        $db = $this->em->getConnection();
        $undoable = function (string $kind, string $state, ?string $note = null) use ($db, $runId): int {
            $db->executeStatement('UPDATE service_inbound_move SET state = ?, skip_reason = ? WHERE run_id = ?', [$state, $note, $runId]);

            return (int) $db->fetchOne('SELECT COUNT(*) FROM service_inbound_move WHERE run_id = ? AND ('.InboundMoveOverview::undoableSql($kind).')', [$runId]);
        };

        self::assertSame(0, $undoable(InboundMoveRun::KIND_PRIMARY, 'done'), 'a retired move needs a new run');
        self::assertSame(1, $undoable(InboundMoveRun::KIND_ADD, 'done'));
        self::assertSame(0, $undoable(InboundMoveRun::KIND_ADD, 'done', 'already_on_panel'), 'this run did not attach it');
        self::assertSame(1, $undoable(InboundMoveRun::KIND_REMOVE, 'done'));
        self::assertSame(1, $undoable(InboundMoveRun::KIND_REMOVE, 'detaching'));
    }

    public function testTheProtocolRulesTakeAPrimaryAndItsAdditionalInbounds(): void
    {
        $planner = self::getContainer()->get(InboundMovePlanner::class);
        $unknown = $this->inboundOf(3, 'wireguard');
        $ssOne = $this->inboundOf(4, 'shadowsocks');
        $ssTwo = $this->inboundOf(5, 'shadowsocks');

        self::assertFalse($planner->protocolRulesBroken($this->de, [$this->nl], false));
        self::assertTrue($planner->protocolRulesBroken($this->de, [$unknown], false));
        self::assertNull($planner->protocolRulesBroken($ssOne, [$ssTwo], false), 'the methods need the panel');
    }

    #[DataProvider('otherKinds')]
    public function testTheRunBlockNamesTheKind(string $kind): void
    {
        $runId = $this->planned(InboundMoveRun::KIND_PRIMARY);
        self::getContainer()->get('translator')->setLocale('en');
        $run = self::getContainer()->get(InboundMoveOverview::class)->planRun((int) $this->gold->getId(), null);
        self::assertNotNull($run);
        $run['kind'] = $kind;
        $run['can'] = array_fill_keys(array_keys($run['can']), false);

        $html = self::getContainer()->get(Environment::class)->render('admin/inbound_move/_run.html.twig', ['plan' => $this->gold, 'run' => $run, 'problems' => ['failed' => 0, 'given_up' => 0], 'enabled' => true]);

        self::assertStringContainsString(sprintf('Run #%d: %s an inbound', $runId, $kind), $html);
    }

    private function planned(string $kind): int
    {
        $this->movableService(1);
        $this->em->clear();
        $plan = $this->em->find($this->gold::class, $this->gold->getId());
        self::assertNotNull($plan);
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($plan, new InboundMoveScope());
        self::assertIsArray($created);
        $this->kind($created[0], $kind);
        $this->fake->requests = [];

        return $created[0];
    }

    private function kind(int $runId, string $kind): void
    {
        $this->em->getConnection()->executeStatement('UPDATE inbound_move_run SET kind = ? WHERE id = ?', [$kind, $runId]);
    }

    private function detachAfter(int $runId, string $when): void
    {
        $this->em->getConnection()->executeStatement('UPDATE service_inbound_move SET detach_after = ? WHERE run_id = ?', [(new \DateTimeImmutable($when))->format('Y-m-d H:i:s'), $runId]);
    }

    private function inboundOf(int $remote, string $protocol): VpnInbound
    {
        $inbound = $this->panelInbound($remote, 'I-'.$remote)->setProtocol($protocol);
        $this->em->flush();

        return $inbound;
    }

    /** @return array<string, mixed> */
    private function rows(int $runId): array
    {
        $db = $this->em->getConnection();

        return [
            'run' => $db->fetchAssociative('SELECT * FROM inbound_move_run WHERE id = ?', [$runId]),
            'moves' => $db->fetchAllAssociative('SELECT * FROM service_inbound_move WHERE run_id = ? ORDER BY id', [$runId]),
        ];
    }
}
