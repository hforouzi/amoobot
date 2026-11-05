<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\InboundAttachAlerts;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P6, phase 2 "retire" of docs/design/plan-inbound-change.md 4.3 step 5, 5.4, 8.1 and 8.2, against the fake modern
 * 3x-ui (detach with the v3.11.0 semantics, P2):
 * - T4 phase 2: after the grace period the old inbound A is detached, the snapshot holds B only, and the client's UUID,
 *   subId, email, expiry, limits and traffic are unchanged on the panel;
 * - the grace period (setting, --grace-hours, 0), --keep-old (never automatically) and --retire-now;
 * - T5 in the retire: never the last inbound nor the local primary; B missing → attached again first; a client on
 *   neither A nor B → failed with nothing sent;
 * - idempotency: a second pass sends nothing; A already gone → done without a detach; A deleted on the panel → done
 *   once inbounds/list confirms it;
 * - retries, back-off, give-up and alerts with the plans.attach_* settings; the automatic pause.
 */
final class InboundMoveRetireTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    public function testT4PhaseTwoAfterTheGracePeriodDetachesTheOldInboundAndKeepsEverythingElse(): void
    {
        $service = $this->movableService(1);
        $this->fake->useTraffic('tg_p1_1', 123456789);
        [$code, $output] = $this->command(['--apply' => true, '--service' => (string) $service->getId()]);
        self::assertSame(0, $code, $output);
        $runId = (int) $this->runs()[0]['id'];
        $move = $this->moves()[0];
        self::assertSame('switched', $move['state']);
        $grace = strtotime((string) $move['detach_after']) - time();
        self::assertGreaterThan(24 * 3600 - 60, $grace, 'detach_after = now + the setting (24 h)');
        self::assertLessThanOrEqual(24 * 3600, $grace);
        self::assertSame(['running', '0', '24'], [$this->runs()[0]['status'], (string) $this->runs()[0]['keep_old'], (string) $this->runs()[0]['grace_hours']]);
        self::assertConsoleContains('the old inbound is removed 24 hour(s) after the switch', $output);
        self::assertConsoleContains('1 moved service(s) keep the old inbound until their grace period ends', $output);
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds'], 'during the grace period: on A and B');

        // Within the grace period a pass sends nothing.
        $this->fake->requests = [];
        [$code] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code);
        self::assertSame([], $this->fake->requests, 'nothing due: no request at all');

        // After it: GET, detach [A], GET, subLinks.
        $record = $this->fake->clients['tg_p1_1'];
        $traffic = $this->fake->traffic['tg_p1_1'];
        $this->elapse();
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): retired: old inbound removed; links refreshed from the panel (1 link(s))', $service->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertConsoleContains('Old inbound removed in this pass: 1.', $output);
        self::assertSame([
            'GET panel/api/clients/get/tg_p1_1',
            'POST panel/api/clients/tg_p1_1/detach',
            'GET panel/api/clients/get/tg_p1_1',
            'GET panel/api/clients/subLinks/'.$service->getSubId(),
        ], $this->fake->requestLines(), 'at most 4 calls: GET, detach, GET, subLinks');
        self::assertSame(['inboundIds' => [1]], $this->requestBody(1), 'A only');
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame(array_diff_key($record, ['inboundIds' => 1]), array_diff_key($this->fake->clients['tg_p1_1'], ['inboundIds' => 1]), 'UUID, email, subId, expiry, totalGB, limitIp, enable unchanged');
        self::assertSame($traffic, $this->fake->traffic['tg_p1_1'], 'traffic kept (keepTraffic)');

        $row = $this->serviceRow((int) $service->getId());
        $links = json_decode((string) $row['config_links'], true);
        self::assertCount(1, $links, 'the snapshot holds B only');
        self::assertStringStartsWith('vmess://', (string) $links[0], 'the link of B (vmess-ws), not of A (vless-reality)');
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at'], 'links refreshed after the retire');

        $move = $this->moves()[0];
        self::assertSame(['done', null, null], [$move['state'], $move['claimed_at'], $move['last_error']]);
        self::assertNotNull($move['detached_at']);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'));
        self::assertSame(['run_id' => $runId, 'planned' => 1, 'done' => 1, 'failed' => 0, 'skipped' => 0, 'remaining' => 0], $this->audits()[1]['details']);

        // Idempotent: a done run is not processed again; nothing is sent.
        $this->fake->requests = [];
        [$code] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(2, $code, 'the run is done');
        self::assertSame([], $this->fake->requests);
        foreach ($this->secretsOf($service) as $secret) {
            self::assertConsoleNotContains($secret, $output);
        }
    }

    public function testGraceHoursZeroRetiresInTheSamePassAndTheOptionsAreChecked(): void
    {
        $service = $this->movableService(1);
        [$code, $output] = $this->command(['--apply' => true, '--grace-hours' => '0']);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(': switched: attached and switched', $output);
        self::assertConsoleContains(': retired: old inbound removed', $output);
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertSame('0', (string) $this->runs()[0]['grace_hours']);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);

        foreach ([
            [['--apply' => true, '--grace-hours' => '169'], '--grace-hours must be from 0 to 168'],
            [['--apply' => true, '--grace-hours' => 'x'], '--grace-hours must be a whole number'],
            [['--apply' => true, '--keep-old' => true, '--grace-hours' => '2'], '--grace-hours has no meaning with it'],
            [['--keep-old' => true], 'use them with --apply'],
            [['--resume' => '1', '--grace-hours' => '2'], 'use them with --apply'],
            [['--apply' => true, '--undo' => '1'], 'Use one of --apply, --resume'],
            [['--pause-run' => '1', '--cancel' => '1'], 'Use one of --apply, --resume'],
            [['--undo' => '1', '--service' => (string) $service->getId()], 'acts on a whole run: it takes no scope option (--service)'],
            [['--retire-now' => null, '--from-inbound' => '1'], '--retire-now takes only --service'],
            [['--undo' => '999999'], 'Run #999999 is not a run of plan'],
        ] as [$input, $message]) {
            [$code, $output] = $this->command($input);
            self::assertSame(2, $code, $message);
            self::assertConsoleContains($message, $output);
        }
    }

    public function testKeepOldNeverRetiresAutomaticallyAndRetireNowDoesForOneServiceOrAll(): void
    {
        $one = $this->movableService(1);
        $two = $this->movableService(2);
        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true]);
        self::assertSame(0, $code, $output);
        self::assertConsoleContains('the old inbound stays: --keep-old', $output);
        self::assertSame([null, null], array_column($this->moves(), 'detach_after'), 'keep-old: never due');
        self::assertSame('done', $this->runs()[0]['status'], 'nothing left that changes by itself');
        $runId = (int) $this->runs()[0]['id'];

        // Retire now, one service: the finished run is reopened (paused) and the next pass removes A for it only.
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--retire-now' => null, '--service' => (string) $one->getId()]);
        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('1 moved service(s) of run #%d are due for the removal of their old inbound now (the finished run was reopened, paused)', $runId), $output);
        self::assertConsoleContains(sprintf('--resume=%d', $runId), $output);
        self::assertSame(['paused', (string) $this->gold->getId()], [$this->runs()[0]['status'], (string) $this->runs()[0]['open_plan_id']]);
        self::assertSame([], $this->panelWritesSince(0), 'retire-now itself sends nothing');
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame([[2], [1, 2]], [$this->fake->clients['tg_p1_1']['inboundIds'], $this->fake->clients['tg_p1_2']['inboundIds']]);
        self::assertSame(['done', 'switched'], array_column($this->moves(), 'state'));
        self::assertSame('done', $this->runs()[0]['status']);

        // Retire now for all, processed in the same command (--retire-now=<run> --resume=<run>).
        [$code, $output] = $this->command(['--retire-now' => (string) $runId, '--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame([2], $this->fake->clients['tg_p1_2']['inboundIds']);
        self::assertSame(['done', 'done'], array_column($this->moves(), 'state'));
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $two->getId())['inbound_id']);

        // Nothing left: retire-now says so and changes nothing.
        [$code, $output] = $this->command(['--retire-now' => (string) $runId]);
        self::assertSame(2, $code);
        self::assertConsoleContains('has no moved service whose old inbound is still attached', $output);
        self::assertSame('done', $this->runs()[0]['status']);
    }

    public function testT5BMissingOnThePanelIsAttachedAgainFirstAndANeitherClientFailsWithNothingSent(): void
    {
        $missingB = $this->movableService(1);
        $neither = $this->movableService(2);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // By hand on the panel during the grace period: B removed from the first client, the second moved elsewhere.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $this->fake->clients['tg_p1_2']['inboundIds'] = [3];
        $this->elapse();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): reattached: the new inbound was missing on the panel: attached again first', $missingB->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): retired: old inbound removed', $missingB->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds'], 'B attached again, then A removed');
        self::assertSame([
            ['panel/api/clients/tg_p1_1/attach', ['inboundIds' => [2]]],
            ['panel/api/clients/tg_p1_1/detach', ['inboundIds' => [1]]],
        ], $this->posts(), 'B first, never A without B listed; nothing for the second client');

        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): failed: old inbound not removed: Not retired: the client is not on the expected inbound', $neither->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([3], $this->fake->clients['tg_p1_2']['inboundIds'], 'nothing sent for a client on neither');
        $move = $this->moves()[1];
        self::assertSame(['failed', 1], [$move['state'], (int) $move['attempts']]);
        self::assertNotNull($move['detach_after'], 'still a retire (phase 2), retried with back-off');
        self::assertNotNull($move['next_attempt_at']);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $neither->getId())['inbound_id'], 'the service stays on B');
    }

    public function testT5NeverTheLastInboundNorTheLocalPrimary(): void
    {
        $lastOne = $this->movableService(1);
        $backOnA = $this->movableService(2);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // The first client is on A only, and B cannot be attached again (B deleted on the panel): A is never detached.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'inbound 2: record not found'), 5);
        // The second service was put back on A in the bot meanwhile (A is its local primary again): never detached.
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET inbound_id = ? WHERE id = ?', [$this->de->getId(), $backOnA->getId()]);
        $this->elapse();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/detach')), 'no detach at all');
        self::assertSame([1], $this->fake->clients['tg_p1_1']['inboundIds'], 'never left on no inbound');
        self::assertConsoleContains('failed: old inbound not removed: The new inbound was missing on the panel and could not be attached again', $output);
        self::assertSame([1, 2], $this->fake->clients['tg_p1_2']['inboundIds']);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): skipped: old inbound not removed: its inbound changed since the run was created', $backOnA->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_2')), 'nothing sent for the second client');
        self::assertSame(['failed', 'skipped'], array_column($this->moves(), 'state'));
    }

    public function testIdempotencyAGoneOldInboundAndAnOldInboundDeletedOnThePanel(): void
    {
        $alreadyOff = $this->movableService(1);
        $deletedOnPanel = $this->movableService(2);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // The first client was taken off A by hand; the second still lists A, which was deleted on the panel.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [2];
        $remoteA = $this->fake->inbounds[1];
        unset($this->fake->inbounds[1]);
        $this->elapse();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): retired: the old inbound was already off the client (nothing sent)', $alreadyOff->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST panel/api/clients/tg_p1_1/')), 'A already gone: no detach');
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): retired: the old inbound no longer exists on the panel (inbounds/list)', $deletedOnPanel->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame(['POST panel/api/clients/tg_p1_2/detach'], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST'))), 'the refused detach only');
        self::assertContains('GET panel/api/inbounds/list', $this->fake->requestLines());
        self::assertSame(['done', 'done'], array_column($this->moves(), 'state'));
        self::assertSame('done', $this->runs()[0]['status']);

        // A run without grace retires at once; a second pass on it sends nothing that changes state.
        $this->fake->inbounds[1] = $remoteA;
        $third = $this->movableService(3);
        [$code] = $this->command(['--apply' => true, '--grace-hours' => '0', '--service' => (string) $third->getId()]);
        self::assertSame(0, $code);
        $this->fake->requests = [];
        [$code] = $this->command(['--resume' => (string) $this->runs()[1]['id']]);
        self::assertSame(2, $code, 'done');
        self::assertSame([], $this->panelWritesSince(0));
    }

    public function testAnOldInboundThatIsAnAdditionalInboundOfTheServiceNowIsKept(): void
    {
        $service = $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        // A became one of the service's additional inbounds during the grace period (the plan lists it again).
        $this->em->getConnection()->insert('vpn_service_inbound', ['service_id' => $service->getId(), 'inbound_id' => $this->de->getId(), 'remote_inbound_id' => '1', 'status' => 'attached', 'attempts' => 0, 'updated_at' => '2026-01-01 00:00:00', 'reattach_count' => 0]);
        $this->elapse();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(': kept: the old inbound is one of the service\'s additional inbounds now: it stays attached (nothing detached)', $output);
        self::assertSame([], $this->fake->requests, 'no request');
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame(['switched', null], [$this->moves()[0]['state'], $this->moves()[0]['detach_after']]);
        self::assertSame('done', $this->runs()[0]['status']);
    }

    public function testARetireBacksOffGivesUpIsAlertedOnceAndRetireNowArmsItAgain(): void
    {
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '2', 'number');
        $service = $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'), 10);
        $before = count($this->http->telegramTexts());
        $this->elapse();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(1, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', 1], [$move['state'], (int) $move['attempts']]);
        $delay = strtotime((string) $move['next_attempt_at']) - time();
        self::assertGreaterThan(4 * 60, $delay, 'plans.attach_retry_delay_minutes (5)');
        self::assertLessThanOrEqual(5 * 60 + 5, $delay);
        self::assertStringContainsString('database is locked', (string) $move['last_error']);
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertSame([], $this->alerts($before));

        // Not due yet: nothing read.
        $this->fake->requests = [];
        $this->command(['--resume' => (string) $runId]);
        self::assertSame([], $this->fake->requests);

        // Due: the last attempt fails too: given up, one alert for the service (retire text), one for the run.
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(1, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', 2, null, 'exhausted'], [$move['state'], (int) $move['attempts'], $move['next_attempt_at'], $move['alert_state']]);
        self::assertSame('done', $this->runs()[0]['status']);
        $gaveUp = $this->alerts($before, InboundAttachAlerts::MOVE_GAVE_UP_TITLE);
        self::assertCount(1, $gaveUp);
        self::assertStringContainsString('of removing the old inbound', $gaveUp[0]);
        self::assertStringContainsString(sprintf('--retire-now=%d --service=%d --resume=%d', $runId, $service->getId(), $runId), $gaveUp[0]);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::MOVE_FINISHED_FAILED_TITLE));
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds'], 'A still attached; the service works on B');
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);

        // Retire now arms it again (attempts from zero), the panel works again: removed.
        $this->fake->clearScripts();
        [$code, $output] = $this->command(['--retire-now' => (string) $runId, '--service' => (string) $service->getId(), '--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame([2], $this->fake->clients['tg_p1_1']['inboundIds']);
        self::assertSame(['done', 1], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']]);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::MOVE_GAVE_UP_TITLE), 'no second alert');
    }

    public function testFiveUnreadableRetiresInARowPauseTheRunWithOneAlert(): void
    {
        array_map(fn (int $n) => $this->movableService($n), range(1, 6));
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        $runId = (int) $this->runs()[0]['id'];
        $this->elapse();
        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Could not resolve host'), times: 100);
        $before = count($this->http->telegramTexts());
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        $run = $this->runs()[0];
        self::assertSame(['paused', 'panel not answering: 5 failed panel reads in a row'], [$run['status'], $run['pause_reason']]);
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed', 'switched'], array_column($this->moves(), 'state'), 'the sixth is not touched');
        self::assertSame([], $this->panelWritesSince(0));
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::MOVE_PAUSED_TITLE));
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_paused'], array_column($this->audits(), 'action'));
        self::assertSame(['run_id' => $runId, 'by' => 'automatic', 'planned' => 6, 'done' => 1, 'failed' => 5, 'skipped' => 0, 'remaining' => 0], $this->audits()[1]['details']);

        $this->fake->clearScripts();
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame(array_fill(0, 6, 'done'), array_column($this->moves(), 'state'));
        foreach (range(1, 6) as $n) {
            self::assertSame([2], $this->fake->clients['tg_p1_'.$n]['inboundIds']);
        }
    }

    /**
     * The stamps have one-second precision: a refresh in the same second as the retire must not read as "already refreshed"
     * when the refresh of the retire itself fails (or the process dies before it): A's link would stay stored, shown, and
     * the run would close. The test pins the synced stamp at or after the retire, so it does not depend on the clock.
     */
    public function testARetireInTheSameSecondAsTheLastRefreshStillWaitsForItsOwnRefresh(): void
    {
        $service = $this->movableService(1);
        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(0, $code, $output);
        $runId = (int) $this->runs()[0]['id'];
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET last_access_info_synced_at = '2099-01-01 00:00:00' WHERE id = ?", [$service->getId()]);
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE state = 'switched'");
        $this->em->clear();
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('connection refused'), 5);

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNull($this->serviceRow((int) $service->getId())['last_access_info_synced_at'], 'pending: the display guard hides the old link');
        self::assertSame('running', $this->runs()[0]['status'], 'the run waits for the refresh');

        $this->fake->clearScripts();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->runs()[0]['status']);
        $row = $this->serviceRow((int) $service->getId());
        self::assertNotNull($row['last_access_info_synced_at']);
        self::assertCount(1, json_decode((string) $row['config_links'], true), 'B only');
    }

    public function testTheGraceSettingIsTheDefault(): void
    {
        $this->setting(InboundMoveSettings::DETACH_GRACE_HOURS, '2', 'number');
        $this->movableService(1);
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(0, $code);
        self::assertSame('2', (string) $this->runs()[0]['grace_hours']);
        $grace = strtotime((string) $this->moves()[0]['detach_after']) - time();
        self::assertGreaterThan(2 * 3600 - 60, $grace);
        self::assertLessThanOrEqual(2 * 3600, $grace);
        self::assertSame(['keep_old' => false, 'grace_hours' => 2], array_intersect_key($this->audits()[0]['details'], ['keep_old' => 1, 'grace_hours' => 1]));
    }

    /** The grace period of every switched move is over. */
    private function elapse(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00' WHERE state = 'switched' AND detach_after IS NOT NULL");
    }

    private function due(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00' WHERE state = 'failed' AND next_attempt_at IS NOT NULL");
    }

    /** @return list<array{mixed, mixed}> path and body of every POST the fake panel received */
    private function posts(): array
    {
        return array_values(array_map(static fn (array $r): array => [$r['path'], $r['body']], array_filter($this->fake->requests, static fn (array $r): bool => 'POST' === $r['method'])));
    }

    /** @return list<string> */
    private function panelWritesSince(int $from): array
    {
        return array_values(array_filter(array_slice($this->fake->requestLines(), $from), static fn (string $l): bool => !str_starts_with($l, 'GET ')));
    }

    /** @return list<string> */
    private function alerts(int $from, ?string $title = null): array
    {
        return array_values(array_unique(array_filter(array_slice($this->http->telegramTexts(), $from), static fn (string $text): bool => null === $title ? 1 === preg_match('/Run ID: \d+/', $text) : str_starts_with($text, $title))));
    }
}
