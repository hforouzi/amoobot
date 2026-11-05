<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Shop\Application\InboundMoveSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P6, the run controls of docs/design/plan-inbound-change.md 5.4 on the command line, against the fake modern 3x-ui:
 * - T10: --undo puts switched services (old inbound not removed yet) back on A: A attached again if missing, the
 *   service on A with its original remote id, B detached unless B is one of the plan's additional inbounds, the links
 *   refreshed; retired services are NOT undone (a new run B → A is needed: the command says for how many);
 * - --cancel: services not moved yet are cancelled; the run stays open while moved services wait for the removal of
 *   their old inbound;
 * - --pause-run / --resume: a pass finishes the service in hand and takes no other; one alert, one audit entry;
 * - with the switch off, nothing that calls the panel runs.
 */
final class InboundMoveControlsTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    public function testT10UndoPutsSwitchedServicesBackOnTheOldInbound(): void
    {
        $one = $this->movableService(1);
        $two = $this->movableService(2);
        $before = [(int) $one->getId() => $this->serviceRow((int) $one->getId()), (int) $two->getId() => $this->serviceRow((int) $two->getId())];
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // By hand during the grace period: A was taken off the second client. Undo attaches it again first.
        $this->fake->clients['tg_p1_2']['inboundIds'] = [2];
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): undone: back on the old inbound; the new inbound removed; links refreshed from the panel (1 link(s))', $one->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([
            'GET panel/api/clients/get/tg_p1_1',
            'GET panel/api/clients/get/tg_p1_1',
            'POST panel/api/clients/tg_p1_1/detach',
            'GET panel/api/clients/get/tg_p1_1',
            'GET panel/api/clients/subLinks/'.$one->getSubId(),
        ], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_1') || str_contains($l, (string) $one->getSubId()))), 'A listed: no attach; B detached');
        self::assertSame(['POST panel/api/clients/tg_p1_2/attach', 'POST panel/api/clients/tg_p1_2/detach'], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST panel/api/clients/tg_p1_2/'))), 'A attached again first, then B detached');
        foreach ([1 => $one, 2 => $two] as $n => $service) {
            self::assertSame([1], $this->fake->clients['tg_p1_'.$n]['inboundIds'], 'back on A only');
            $row = $this->serviceRow((int) $service->getId());
            self::assertSame((string) $this->de->getId(), (string) $row['inbound_id']);
            self::assertSame($before[(int) $service->getId()]['remote_id'], $row['remote_id'], 'the original remote id, byte for byte');
            self::assertSame([(string) $service->getClientUuid(), (string) $service->getSubId()], [$row['client_uuid'], $row['sub_id']]);
            $links = json_decode((string) $row['config_links'], true);
            self::assertCount(1, $links);
            self::assertStringStartsWith('vless://', (string) $links[0], 'the links of A');
            self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at']);
        }
        self::assertSame([['undone', null, null], ['undone', null, null]], array_map(static fn (array $m): array => [$m['state'], $m['next_attempt_at'], $m['claimed_at']], $this->moves()));
        self::assertSame(['cancelled', null], [$this->runs()[0]['status'], $this->runs()[0]['open_plan_id']]);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_undone'], array_column($this->audits(), 'action'));
        self::assertSame(['run_id' => $runId, 'undone' => 2, 'failed' => 0, 'skipped' => 0, 'busy' => 0, 'cancelled' => 0, 'already_retired' => 0], $this->audits()[1]['details']);

        // Nothing left: a second undo sends nothing.
        $this->fake->requests = [];
        [$code] = $this->command(['--undo' => (string) $runId]);
        self::assertSame(0, $code);
        self::assertSame([], $this->fake->requests);
        foreach ([...$this->secretsOf($one), ...$this->secretsOf($two)] as $secret) {
            self::assertConsoleNotContains($secret, $output);
        }
    }

    public function testT10UndoIsRefusedForRetiredServicesAndSaysANewRunIsNeeded(): void
    {
        $retired = $this->movableService(1);
        $switched = $this->movableService(2);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        [$code] = $this->command(['--retire-now' => (string) $runId, '--service' => (string) $retired->getId(), '--resume' => (string) $runId]);
        self::assertSame(0, $code);
        self::assertSame(['done', 'switched'], array_column($this->moves(), 'state'));
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(1, $code, 'not everything could be undone');
        self::assertConsoleContains('The old inbound was already removed from 1 services: undo cannot put them back.', $output);
        self::assertConsoleContains(sprintf('That needs a new run from the new inbound back to the old one for these 1 service(s): set the plan\'s inbound back to the old one, then run app:plan:apply-inbounds %d (dry run first).', $this->gold->getId()), $output);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_1')), 'nothing sent for the retired service');
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $retired->getId())['inbound_id']);
        self::assertSame([1], $this->fake->clients['tg_p1_2']['inboundIds'], 'the switched one is undone');
        self::assertSame(['done', 'undone'], array_column($this->moves(), 'state'));
        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertSame(1, $this->audits()[1]['details']['already_retired'] ?? null);

        [, $json] = $this->command(['--undo' => (string) $runId, '--json' => true]);
        $decoded = json_decode($json, true);
        self::assertSame(1, $decoded['pass']['already_retired']);
        self::assertStringContainsString('the old inbound was already removed from 1 services', strtolower((string) $decoded['already_retired_note']));
    }

    public function testT10UndoKeepsTheNewInboundWhenItIsOneOfThePlansAdditionalInbounds(): void
    {
        $service = $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // The owner goes back: the plan sells DE-1 again, with NL-2 as an additional inbound.
        $this->sellOn($this->gold, $this->de);
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->nl)]);
        $this->em->flush();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('undone: back on the old inbound; the new one stays attached (one of the plan\'s additional inbounds)', $output);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST')), 'nothing detached, nothing attached');
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds']);
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->de->getId(), (string) $row['inbound_id']);
        self::assertSame([(int) $this->nl->getId() => 'attached'], $this->em->getConnection()->fetchAllKeyValue('SELECT inbound_id, status FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]), 'B is an attached additional row now');
        self::assertCount(2, json_decode((string) $row['config_links'], true), 'links of A and B');
        self::assertSame(['undone', null], [$this->moves()[0]['state'], $this->moves()[0]['next_attempt_at']]);
    }

    public function testAnUndoThatCannotFinishIsContinuedByTheNextUndo(): void
    {
        $service = $this->movableService(1);
        $other = $this->movableService(2);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // The first one's attach of A is refused (nothing applied); the second one's detach of B is refused.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [2];
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->fake->script('POST', 'panel/api/clients/tg_p1_2/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): failed: not undone:', $service->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertConsoleContains('back on the old inbound, but the new inbound could not be removed yet', $output);
        self::assertConsoleContains(sprintf('run --undo=%d again', $runId), $output);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id'], 'nothing written for the first');
        self::assertSame('switched', $this->moves()[0]['state']);
        self::assertStringContainsString('database is locked', (string) $this->moves()[0]['last_error']);
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $other->getId())['inbound_id'], 'the second is back on A');
        self::assertSame('undone', $this->moves()[1]['state']);
        self::assertNotNull($this->moves()[1]['next_attempt_at'], 'B still to be removed');
        self::assertSame([1, 2], $this->fake->clients['tg_p1_2']['inboundIds']);
        self::assertSame(['paused', 'undo incomplete: 2 service(s) not put back yet (run --undo again)'], [$this->runs()[0]['status'], $this->runs()[0]['pause_reason']]);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::MOVE_PAUSED_TITLE), 'one alert for the run');

        $this->fake->clearScripts();
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame([[1], [1]], [$this->fake->clients['tg_p1_1']['inboundIds'], $this->fake->clients['tg_p1_2']['inboundIds']]);
        self::assertSame(['POST panel/api/clients/tg_p1_1/attach', 'POST panel/api/clients/tg_p1_1/detach', 'POST panel/api/clients/tg_p1_2/detach'], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST'))), 'the second one only finishes its detach');
        self::assertSame([['undone', null], ['undone', null]], array_map(static fn (array $m): array => [$m['state'], $m['next_attempt_at']], $this->moves()));
        self::assertSame('cancelled', $this->runs()[0]['status']);
    }

    public function testPauseRunFinishesTheServiceInHandAndTakesNoOtherCancelThenStopsTheRest(): void
    {
        $services = array_map(fn (int $n) => $this->movableService($n), range(1, 4));
        $runId = $this->createRun();
        $controls = self::getContainer()->get(InboundMoveControls::class);
        $paused = [];
        // The admin pauses the run while the runner waits for the second client's attach.
        $this->fake->onRequest = static function (string $phase, string $method, string $path) use ($controls, $runId, &$paused): void {
            if ('before' === $phase && 'POST panel/api/clients/tg_p1_2/attach' === $method.' '.$path && [] === $paused) {
                $paused = $controls->pause($runId);
            }
        };
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertTrue($paused['ok'] ?? false);
        self::assertSame(1, $code, $output);
        self::assertSame(['switched', 'switched', 'planned', 'planned'], array_column($this->moves(), 'state'), 'the second (in hand) finished, no other taken');
        self::assertSame(['paused', InboundMoveControls::PAUSED_BY_ADMIN], [$this->runs()[0]['status'], $this->runs()[0]['pause_reason']]);
        self::assertConsoleContains(sprintf('Run #%d is paused (paused by an admin (--pause-run))', $runId), $output);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_paused'], array_column($this->audits(), 'action'));
        self::assertSame('admin', $this->audits()[1]['details']['by']);
        $alerts = $this->alerts($before, InboundAttachAlerts::MOVE_PAUSED_TITLE);
        self::assertCount(1, $alerts);
        self::assertStringContainsString(sprintf('--resume=%d', $runId), $alerts[0]);
        $this->fake->onRequest = null;

        [$code, $output] = $this->command(['--pause-run' => (string) $runId]);
        self::assertSame(2, $code);
        self::assertConsoleContains(sprintf('Run #%d is paused: only a planned or running run can be paused.', $runId), $output);

        // Cancel: the two not moved yet will not be; the two moved still wait for their retire: the run stays open.
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--cancel' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Run #%d: 2 service(s) not moved yet will not be moved. 2 moved service(s) still wait for their old inbound to be removed', $runId), $output);
        self::assertSame(['switched', 'switched', 'cancelled', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame([], $this->fake->requests, 'cancel calls no panel');
        self::assertSame(['run_id' => $runId, 'cancelled' => 2, 'still_open' => true], array_intersect_key($this->audits()[2]['details'], ['run_id' => 1, 'cancelled' => 1, 'still_open' => 1]));

        // Resume after the grace period: the two moved ones are retired, the cancelled ones untouched; the run is done.
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE state = 'switched'");
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done', 'cancelled', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame([[2], [2], [1], [1]], array_map(fn (int $n): array => $this->fake->clients['tg_p1_'.$n]['inboundIds'], range(1, 4)));
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $services[3]->getId())['inbound_id']);
        self::assertSame('done', $this->runs()[0]['status']);

        // A finished run cannot be cancelled or paused.
        [$code, $output] = $this->command(['--cancel' => (string) $runId]);
        self::assertSame(2, $code);
        self::assertConsoleContains(sprintf('Run #%d is done: nothing to cancel.', $runId), $output);
    }

    public function testCancelWithNothingMovedClosesTheRunAndUndoCancelsWhatIsLeft(): void
    {
        $this->movableService(1);
        $this->movableService(2);
        $runId = $this->createRun();

        [$code, $output] = $this->command(['--cancel' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Run #%d is cancelled: 2 service(s) not moved yet will not be moved.', $runId), $output);
        self::assertSame(['cancelled', null], [$this->runs()[0]['status'], $this->runs()[0]['open_plan_id']]);
        self::assertSame(['cancelled', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->fake->requests);

        // A new run for the plan is possible again; undo of a planned run cancels its services and closes it.
        $second = $this->createRun();
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--undo' => (string) $second]);
        self::assertSame(0, $code, $output);
        self::assertConsoleContains('services not moved yet that will not be moved: 2', $output);
        self::assertSame('cancelled', $this->runs()[1]['status']);
        self::assertSame([], $this->fake->requests);
    }

    public function testWithTheSwitchOffNothingThatCallsThePanelRuns(): void
    {
        $service = $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00'");
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $this->fake->requests = [];
        $before = ['moves' => $this->moves(), 'runs' => $this->runs(), 'service' => $this->serviceRow((int) $service->getId())];

        foreach ([['--resume' => (string) $runId], ['--undo' => (string) $runId], ['--retire-now' => (string) $runId], ['--retire-now' => (string) $runId, '--resume' => (string) $runId]] as $input) {
            [$code, $output] = $this->command($input);
            self::assertSame(2, $code, $output);
            self::assertConsoleContains('switched off (plans.inbound_move_enabled)', $output);
        }
        self::assertSame([], $this->fake->requests);
        self::assertSame($before, ['moves' => $this->moves(), 'runs' => $this->runs(), 'service' => $this->serviceRow((int) $service->getId())]);
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds']);
    }

    private function createRun(): int
    {
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($this->gold), new InboundMoveScope());
        self::assertIsArray($created, is_string($created) ? $created : '');

        return $created[0];
    }

    /** @return list<string> */
    private function alerts(int $from, ?string $title = null): array
    {
        return array_values(array_unique(array_filter(array_slice($this->http->telegramTexts(), $from), static fn (string $text): bool => null === $title ? 1 === preg_match('/Run ID: \d+/', $text) : str_starts_with($text, $title))));
    }
}
