<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\InboundMoveRun;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Shop\Application\InboundMoveSettings;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P4, app:plan:apply-inbounds against the fake modern 3x-ui (docs/design/plan-inbound-change.md 8.2):
 * - T3: a dry run writes nothing, sends GETs only, lists every service with its current inbound, target and action,
 *   and works with the switch off;
 * - T4 (phase 1): one service: B attached, the service on B locally, the remote id rewritten with every other part
 *   byte-identical, the snapshot = the panel's subLinks (A and B), inbound_changed_at set, and the client's UUID, subId,
 *   email, expiry, limits and traffic unchanged on the panel; the old inbound stays;
 * - T6: running again after it is done sends nothing; a client already on B gets no attach call;
 * - T11: with the switch off --apply is refused (exit 2) and the runner does nothing; one open run per plan.
 */
final class InboundMovePhaseOneTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    public function testT3ADryRunListsEveryServiceReadsThePanelOnlyAndWritesNothing(): void
    {
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $one = $this->movableService(1);
        $two = $this->movableService(2);
        $this->fake->clients['tg_p1_2']['inboundIds'] = [1, 2];
        $this->fake->requests = [];
        $before = $this->checksums() + ['moves' => $this->moves(), 'runs' => $this->runs()];

        [$code, $output] = $this->command([]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('dry run', $output);
        self::assertMatchesRegularExpression('/#'.$one->getId().'\s.*DE-1.*NL-2.*attach \+ switch.*on the old inbound: would attach the target/', self::collapseWhitespace($output));
        self::assertMatchesRegularExpression('/#'.$two->getId().'\s.*already on the target on the panel: no attach needed/', self::collapseWhitespace($output));
        self::assertConsoleContains('2 service(s): 2 would be moved, 0 skipped. Nothing was changed.', $output);
        self::assertConsoleContains('plans.inbound_move_enabled is off: --apply would be refused', $output);
        self::assertSame($before, $this->checksums() + ['moves' => $this->moves(), 'runs' => $this->runs()], 'nothing written');
        self::assertNotSame([], $this->fake->requestLines());
        self::assertSame([], $this->panelWrites(), 'the request log of a dry run holds GETs only');
        foreach ([...$this->secretsOf($one), ...$this->secretsOf($two)] as $secret) {
            self::assertConsoleNotContains($secret, $output);
        }

        [$code, $json] = $this->command(['--json' => true]);
        self::assertSame(0, $code);
        $decoded = json_decode($json, true);
        self::assertSame(['services' => 2, 'would_move' => 2, 'skipped' => 0], $decoded['summary']);
        self::assertSame([$one->getId(), $two->getId()], array_column($decoded['services'], 'service_id'));
        self::assertSame([], $this->panelWrites());
    }

    public function testT4OneServicePhaseOneAttachesSwitchesAndRefreshesKeepingTheOldInbound(): void
    {
        $service = $this->movableService(1);
        $other = $this->movableService(2);
        $record = $this->fake->clients['tg_p1_1'];
        $this->fake->useTraffic('tg_p1_1', 123456789);
        $traffic = $this->fake->traffic['tg_p1_1'];
        $remoteBefore = (string) $service->getRemoteId();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true, '--service' => (string) $service->getId()]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): switched: attached and switched; links refreshed from the panel (2 link(s))', $service->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame([
            'GET panel/api/clients/get/tg_p1_1',
            'POST panel/api/clients/tg_p1_1/attach',
            'GET panel/api/clients/get/tg_p1_1',
            'GET panel/api/clients/subLinks/'.$service->getSubId(),
        ], $this->fake->requestLines(), 'at most 4 calls: GET, attach, GET, subLinks');
        self::assertSame(['inboundIds' => [2]], $this->requestBody(1));

        // Panel: on A and B; the record otherwise untouched.
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds'], 'the old inbound stays (phase 1)');
        self::assertSame(array_diff_key($record, ['inboundIds' => 1]), array_diff_key($this->fake->clients['tg_p1_1'], ['inboundIds' => 1]), 'UUID, email, subId, expiry, totalGB, limitIp, enable unchanged');
        self::assertSame($traffic, $this->fake->traffic['tg_p1_1']);

        // Local: on B, remote id rewritten, snapshot = subLinks, inbound_changed_at set.
        $row = $this->serviceRow((int) $service->getId());
        self::assertSame((string) $this->nl->getId(), (string) $row['inbound_id']);
        $expected = str_replace(['|inbound='.$this->de->getId().'|', '|remoteInbound=1|'], ['|inbound='.$this->nl->getId().'|', '|remoteInbound=2|'], $remoteBefore);
        self::assertNotSame($remoteBefore, $expected);
        self::assertSame($expected, $row['remote_id'], 'only the inbound parts changed, the rest byte-identical');
        $ref = (new Sanaei3xuiRemoteIdParser())->parse((string) $row['remote_id']);
        self::assertSame(['2', (int) $this->nl->getId(), (string) $service->getClientUuid(), 'tg_p1_1', (string) $service->getSubId()], [$ref?->inboundId, $ref?->localInboundId, $ref?->clientId, $ref?->email, $ref?->subId]);
        $links = json_decode((string) $row['config_links'], true);
        self::assertCount(2, $links, 'one link per inbound (A and B) through the host group');
        self::assertSame(implode("\n", $links), $row['config_text']);
        self::assertSame($this->subLinks((string) $service->getSubId()), $links, 'the snapshot is exactly the panel\'s subLinks (A and B)');
        self::assertNotNull($row['inbound_changed_at']);
        self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at']);
        self::assertSame([(string) $service->getClientUuid(), (string) $service->getSubId(), 'tg_p1_1', (string) $service->getPublicToken()], [$row['client_uuid'], $row['sub_id'], $row['client_email'], $row['public_token']]);

        // The other service: untouched.
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $other->getId())['inbound_id']);
        self::assertSame([1], $this->fake->clients['tg_p1_2']['inboundIds']);

        // The run: done, one move switched, frozen target, keep-old, audited with counts only.
        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame([InboundMoveRun::STATUS_DONE, null, (string) $this->nl->getId(), '1', '1', '1'], [$runs[0]['status'], $runs[0]['open_plan_id'], (string) $runs[0]['target_inbound_id'], (string) $runs[0]['keep_old'], (string) $runs[0]['planned_count'], (string) $runs[0]['done_count']]);
        self::assertSame(['switched'], array_column($this->moves(), 'state'));
        self::assertNull($this->moves()[0]['detach_after'], 'keep-old: nothing is due to be detached');
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'));
        self::assertSame(['run_id' => (int) $runs[0]['id'], 'kind' => 'primary', 'target_inbound_id' => $this->nl->getId(), 'services' => 1, 'planned' => 1, 'skipped' => 0, 'keep_old' => true, 'grace_hours' => 24], $this->audits()[0]['details']);
        self::assertSame(['run_id' => (int) $runs[0]['id'], 'planned' => 1, 'done' => 1, 'failed' => 0, 'skipped' => 0, 'remaining' => 0], $this->audits()[1]['details']);
        foreach ($this->secretsOf($service) as $secret) {
            self::assertConsoleNotContains($secret, $output);
            self::assertStringNotContainsString($secret, (string) json_encode($this->audits()));
        }
    }

    public function testT6RunningAgainAfterItIsDoneSendsNothingAndAClientAlreadyOnBGetsNoAttach(): void
    {
        $service = $this->movableService(1);
        $byHand = $this->movableService(2);
        $this->fake->clients['tg_p1_2']['inboundIds'] = [1, 2];
        $this->fake->requests = [];

        // --keep-old: this is about phase 1 (P6 removes the old inbound after the grace period otherwise).
        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['POST panel/api/clients/tg_p1_1/attach'], $this->attachCalls(), 'B already attached by hand: no attach call for it');
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): switched: already on the target: switched without an attach', $byHand->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $byHand->getId())['inbound_id']);
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true]);
        self::assertSame(0, $code, $output);
        self::assertSame([], $this->fake->requests, 'nothing left to move: no request at all');
        self::assertCount(2, $this->runs(), 'an empty second run, done at once');
        self::assertSame(InboundMoveRun::STATUS_DONE, $this->runs()[1]['status']);

        // The runner on a finished run does nothing either.
        $pass = self::getContainer()->get(InboundMoveRunner::class)->process((int) $this->runs()[0]['id']);
        self::assertSame(InboundMovePass::NOT_OPEN, $pass->outcome);
        self::assertSame([], $this->fake->requests);
    }

    /** P6: a service skipped at its claim (its inbound changed since the run was created) is reported as skipped. */
    public function testASkipAtTheClaimIsReportedAsSkippedNotBusy(): void
    {
        $service = $this->movableService(1);
        $created = self::getContainer()->get(\App\Provisioning\Application\InboundMovePlanner::class)->create($this->managed($this->gold), new \App\Provisioning\Application\InboundMoveScope());
        self::assertIsArray($created);
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET inbound_id = ? WHERE id = ?', [$this->nl->getId(), $service->getId()]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $created[0]]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(sprintf('Service #%d: "DE-1" (#%d) → "NL-2" (#%d): skipped: already on the target inbound', $service->getId(), $this->de->getId(), $this->nl->getId()), $output);
        self::assertSame(['skipped', 'already_on_target'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
    }

    public function testT11WithTheSwitchOffNothingRunsAndExistingRowsAreUntouched(): void
    {
        $service = $this->movableService(1);
        [$code] = $this->command(['--apply' => true, '--service' => (string) $service->getId()]);
        self::assertSame(0, $code);
        $other = $this->movableService(2);
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $this->fake->requests = [];
        $before = $this->checksums() + ['moves' => $this->moves(), 'runs' => $this->runs()];

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('switched off (plans.inbound_move_enabled)', $output);
        self::assertSame($before, $this->checksums() + ['moves' => $this->moves(), 'runs' => $this->runs()]);
        self::assertSame([], $this->fake->requests);

        // An open run is not continued either.
        $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', open_plan_id = plan_id, finished_at = NULL");
        $runId = (int) $this->runs()[0]['id'];
        self::assertSame(InboundMovePass::SWITCHED_OFF, self::getContainer()->get(InboundMoveRunner::class)->process($runId)->outcome);
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(2, $code, $output);
        self::assertSame([], $this->fake->requests);
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $other->getId())['inbound_id']);

        // The dry run still works.
        [$code, $output] = $this->command([]);
        self::assertSame(0, $code, $output);
        self::assertSame([], $this->panelWrites());
    }

    public function testASecondApplyIsRefusedNamingTheOpenRunAndScopeOptionsAreChecked(): void
    {
        $service = $this->movableService(1);
        $this->em->getConnection()->insert('inbound_move_run', [
            'plan_id' => $this->gold->getId(), 'open_plan_id' => $this->gold->getId(), 'kind' => 'primary', 'target_inbound_id' => $this->nl->getId(),
            'scope' => '{}', 'status' => 'paused', 'grace_hours' => 24, 'keep_old' => 1, 'created_at' => '2026-01-01 00:00:00',
            'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0,
        ]);
        $open = (int) $this->em->getConnection()->lastInsertId();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(2, $code);
        self::assertConsoleContains(sprintf('open run (#%d)', $open), $output);
        self::assertConsoleContains(sprintf('--resume=%d', $open), $output);
        self::assertCount(1, $this->runs());
        self::assertSame([], $this->fake->requests);

        [$code, $output] = $this->command(['--resume' => (string) $open, '--service' => (string) $service->getId()]);
        self::assertSame(2, $code);
        self::assertConsoleContains('--resume takes no scope option (--service)', $output);
        [$code, $output] = $this->command(['--service' => '999999']);
        self::assertSame(2, $code);
        self::assertConsoleContains('is not a service of plan', $output);
        [$code] = $this->command(['--batch-size' => '0']);
        self::assertSame(2, $code);
        [$code, $output] = $this->runAgainst(999999);
        self::assertSame(2, $code, $output);
    }

    /** @return array{int, string} */
    private function runAgainst(int $planId): array
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? self::bootKernel());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:plan:apply-inbounds'));

        return [$tester->execute(['planId' => (string) $planId]), $tester->getDisplay()];
    }

    /** @return list<string> */
    private function subLinks(string $subId): array
    {
        $lines = $this->fake->requests;
        $content = $this->fake->client()->request('GET', '/secret/panel/api/clients/subLinks/'.$subId, ['headers' => ['Authorization' => 'Bearer '.\App\Tests\Support\Fake3xui\Fake3xuiPanel::TOKEN]])->getContent();
        $this->fake->requests = $lines;

        return json_decode($content, true)['obj'];
    }
}
