<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\InboundMoveRun;
use App\Entity\TrialPlan;
use App\Entity\VpnPanel;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\InboundAttachRun;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\InboundMoveScheduler;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PR P11b, kind=add from the command line and the scheduled task (docs/design/plan-inbound-add-remove.md 11.1), on the
 * fake 3x-ui: the steps of 5.1, the plan rule and the pause of 6.3, the display guard of 6.4, and undo (7). The races
 * and kills are InboundMoveAddParallelTest; redaction is InboundMoveAddRedactionTest.
 */
final class InboundMoveAddTest extends IntegrationTestCase
{
    use InboundAddFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // admin_user is not purged: linked admins left by an earlier test would also get the alerts counted here.
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->setUpAdd();
    }

    public function testAnAddPutsTheClientOnTheInboundAndChangesNothingElse(): void
    {
        $services = [1 => $this->goldService(1), 2 => $this->goldService(2)];
        $before = [1 => $this->identityOf($services[1], 1), 2 => $this->identityOf($services[2], 2)];
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], array_column($this->moves(), 'state'));
        foreach ($services as $n => $service) {
            self::assertSame([1, 2], $this->onPanel($n));
            self::assertSame($before[$n], $this->identityOf($service, $n), 'UUID, subId, email, expiry, limits, usage unchanged');
            $row = $this->nlRow($service);
            self::assertSame(['attached', '2', 0, null], [$row['status'] ?? null, $row['remote_inbound_id'] ?? null, (int) ($row['attempts'] ?? -1), $row['last_error'] ?? null]);
            self::assertNotNull($row['attached_at'] ?? null);
            self::assertNotNull($row['checked_at'] ?? null);
            $stored = $this->serviceRow((int) $service->getId());
            self::assertNull($stored['inbound_changed_at'], 'an add never hides the links (6.4)');
            self::assertCount(2, json_decode((string) $stored['config_links'], true), 'NL-2\'s link too, from the panel');
            self::assertSame(['GET panel/api/clients/get/tg_p1_'.$n, 'POST panel/api/clients/tg_p1_'.$n.'/attach', 'GET panel/api/clients/get/tg_p1_'.$n], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_'.$n.'/') || str_contains($l, 'get/tg_p1_'.$n))));
        }
        self::assertSame([], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'clients/update'))), 'the client record is never sent');

        $run = $this->runs()[0];
        self::assertSame([InboundMoveRun::KIND_ADD, (string) $this->nl->getId(), 0, 0, 'done'], [$run['kind'], (string) $run['target_inbound_id'], (int) $run['grace_hours'], (int) $run['keep_old'], $run['status']]);
        $move = $this->moves()[0];
        self::assertSame([null, null, (string) $this->nl->getId(), '2', null, null], [$move['from_inbound_id'], $move['from_remote_inbound_id'], (string) $move['to_inbound_id'], $move['to_remote_inbound_id'], $move['detach_after'], $move['skip_reason']]);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'));
        foreach ($this->audits() as $audit) {
            self::assertSame(['add', (int) $this->nl->getId()], [$audit['details']['kind'] ?? null, $audit['details']['target_inbound_id'] ?? null]);
        }
        self::assertConsoleContains('Added 2, failed 0, skipped 0, remaining 0 (of 2)', $output);
    }

    public function testTheLinksStayVisibleWhileTheInboundIsAdded(): void
    {
        $service = $this->goldService(1);
        $seen = [];
        $this->fake->onRequest = function (string $phase, string $method, string $path) use ($service, &$seen): void {
            if ('before' === $phase) {
                $row = $this->em->getConnection()->fetchAssociative('SELECT inbound_changed_at FROM vpn_service WHERE id = ?', [$service->getId()]);
                $seen[] = null === ($row['inbound_changed_at'] ?? null);
            }
        };

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertNotContains(false, $seen);
        $this->em->clear();
        $fresh = $this->em->find($service::class, $service->getId());
        self::assertNotNull($fresh);
        $links = self::getContainer()->get(FinalConfigLinkProvider::class);
        self::assertFalse($links->linksPending($fresh));
    }

    public function testARefreshThatFailsLeavesTheMoveSwitchedAndTheNextPassFinishesIt(): void
    {
        $service = $this->goldService(1);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('switched', $this->moves()[0]['state']);
        self::assertSame('running', $this->runs()[0]['status'], 'open until the links are refreshed');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null, 'the row is written first');
        self::assertNull($this->serviceRow((int) $service->getId())['inbound_changed_at']);
        self::assertConsoleContains('--resume='.$this->runs()[0]['id'], $output);

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertSame([], $this->panelWrites(), 'nothing attached again');
    }

    public function testADryRunWritesNothingAndShowsWhatWouldBeAdded(): void
    {
        $plain = $this->goldService(1);
        $already = $this->goldService(2);
        $this->fake->clients['tg_p1_2']['inboundIds'] = [1, 2];
        $elsewhere = $this->goldService(3);
        $this->fake->clients['tg_p1_3']['inboundIds'] = [3];
        $legacy = $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 4);
        $hasRow = $this->goldService(5);
        $this->rowFor($hasRow, 'attached');
        $this->fake->requests = [];
        $sums = $this->checksums();

        [$code, $output] = $this->add(['--json' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->panelWrites());
        self::assertSame($sums, $this->checksums());
        self::assertSame([], $this->runs());
        $decoded = json_decode($output, true);
        self::assertSame('add', $decoded['kind']);
        self::assertSame((int) $this->nl->getId(), $decoded['target_inbound_id']);
        $services = array_column($decoded['services'], null, 'service_id');
        self::assertArrayNotHasKey((int) $hasRow->getId(), $services, 'it has the row: not listed');
        self::assertSame(['add', ['DE-1'], ['DE-1', 'NL-2']], [$services[$plain->getId()]['action'], $services[$plain->getId()]['inbounds_now'], $services[$plain->getId()]['inbounds_after']]);
        self::assertSame(['add', ['DE-1', 'NL-2'], ['DE-1', 'NL-2']], [$services[$already->getId()]['action'], $services[$already->getId()]['inbounds_now'], $services[$already->getId()]['inbounds_after']]);
        self::assertStringContainsString('only the row', (string) $services[$already->getId()]['note']);
        self::assertSame('skip', $services[$legacy->getId()]['action']);
        self::assertSame('suspended', $services[$legacy->getId()]['skip_reason']);
        self::assertStringContainsString('would fail', (string) $services[$elsewhere->getId()]['note']);

        [$code, $table] = $this->add();
        self::assertSame(0, $code, $table);
        self::assertConsoleContains('Now on panel', $table);
        self::assertConsoleContains('4 service(s): 3 would get the inbound, 1 skipped.', $table);
        foreach ($this->secretsOf($plain) as $secret) {
            self::assertStringNotContainsString($secret, $output.$table);
        }
    }

    public function testADryRunWorksWithTheSwitchesOff(): void
    {
        $this->goldService(1);
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');

        [$code, $output] = $this->add();

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(InboundMoveSettings::ENABLED, $output);
        self::assertConsoleContains(MultiInboundSettings::ENABLED, $output);
    }

    /** @return iterable<string, array{string}> */
    public static function switches(): iterable
    {
        yield 'moves off' => [InboundMoveSettings::ENABLED];
        yield 'multi-inbound off' => [MultiInboundSettings::ENABLED];
    }

    #[DataProvider('switches')]
    public function testApplyNeedsBothSwitches(string $key): void
    {
        $this->goldService(1);
        $this->setting($key, 'false', 'boolean');
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains($key, $output);
        self::assertSame([], $this->runs());
        self::assertSame([], $this->fake->requests);
    }

    public function testThePlanRuleRefusesATargetOutsideThePlan(): void
    {
        $this->goldService(1);
        $this->sellNoMore();

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('add it to the plan first', $output);
        self::assertSame([], $this->runs());

        [$code, $output] = $this->command(['--kind' => 'add', '--inbound' => (string) $this->de->getId(), '--apply' => true]);
        self::assertSame(2, $code, 'the primary is not an additional inbound: '.$output);
        self::assertConsoleContains('add it to the plan first', $output);
    }

    public function testThePlanRuleRefusesAnInactiveTargetAndOneOnAnotherPanel(): void
    {
        $this->goldService(1);
        $this->managed($this->nl)->setIsActive(false);
        $this->em->flush();

        [$code, $output] = $this->add(['--apply' => true]);
        self::assertSame(2, $code, $output);
        self::assertConsoleContains('inactive', $output);

        $this->managed($this->nl)->setIsActive(true);
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://panel2.example.test:2053/x/')->setPlainApiToken('other-token-0123456789')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($other);
        $this->managed($this->nl)->setPanel($other);
        $this->em->flush();

        [$code, $output] = $this->add(['--apply' => true]);
        self::assertSame(2, $code, $output);
        self::assertConsoleContains('another panel', $output);
        self::assertSame([], $this->runs());
    }

    public function testATrialBackingPlanIsRefused(): void
    {
        $this->goldService(1);
        $this->em->persist((new TrialPlan())->setTitle('trial')->setDurationDays(1)->setInbound($this->managed($this->de))->setBackingPlan($this->managed($this->gold)));
        $this->em->flush();

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('trial', $output);
        self::assertSame([], $this->runs());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedOptions(): iterable
    {
        yield 'keep-old' => [['--kind' => 'add', '--inbound' => '1', '--apply' => true, '--keep-old' => true], '--keep-old'];
        yield 'grace-hours' => [['--kind' => 'add', '--inbound' => '1', '--apply' => true, '--grace-hours' => '1'], '--grace-hours'];
        yield 'include-trials' => [['--kind' => 'add', '--inbound' => '1', '--include-trials' => true], '--include-trials'];
        yield 'retire-now' => [['--kind' => 'add', '--inbound' => '1', '--retire-now' => null], '--retire-now'];
        yield 'no inbound' => [['--kind' => 'add'], '--inbound'];
        yield 'inbound with primary' => [['--inbound' => '1'], '--inbound'];
        yield 'unknown kind' => [['--kind' => 'other'], '--kind'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedOptions')]
    public function testOptionsThatDoNotApplyAreRefused(array $input, string $named): void
    {
        $this->goldService(1);
        $this->fake->requests = [];

        [$code, $output] = $this->command($input);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains($named, $output);
        self::assertSame([], $this->runs());
        self::assertSame([], $this->fake->requests);
    }

    public function testAnUnknownInboundIsRefused(): void
    {
        [$code, $output] = $this->command(['--kind' => 'add', '--inbound' => '999999']);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('no inbound #999999', $output);
    }

    public function testRetireNowNeverTouchesAnAddRun(): void
    {
        $service = $this->goldService(1);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));
        $this->add(['--apply' => true]);
        $runId = (int) $this->runs()[0]['id'];
        self::assertSame('switched', $this->moves()[0]['state']);

        [$code, $output] = $this->command(['--retire-now' => (string) $runId]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('only for a move', $output);
        self::assertNull($this->moves()[0]['detach_after'], 'a detach_after here would make the runner retire the primary');
    }

    public function testGuardsSkipWithZeroRequests(): void
    {
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://panel2.example.test:2053/x/')->setPlainApiToken('other-token-0123456789')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $dummy = (new VpnPanel())->setType('dummy')->setTitle('dummy');
        $this->em->persist($other);
        $this->em->persist($dummy);
        $this->em->flush();
        $onOther = $this->goldService(1)->setPanel($other);
        $onDummy = $this->goldService(2)->setPanel($dummy);
        $this->em->flush();
        $this->http->on('panel2.example.test', static fn () => throw new \LogicException('the other panel was called'));
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([['skipped', 'other_panel'], ['skipped', 'not_real_panel']], array_map(static fn (array $m): array => [$m['state'], $m['skip_reason']], $this->moves()));
        self::assertSame([], $this->fake->requests);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertNull($this->nlRow($onOther));
        self::assertNull($this->nlRow($onDummy));

        $legacy = $this->planService($this->gold, $this->de, n: 3);
        $this->managed($this->modernPanel)->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();
        $this->fake->requests = [];
        [$code, $output] = $this->add(['--apply' => true, '--service' => (string) $legacy->getId()]);
        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'not_modern'], [$this->moves()[2]['state'], $this->moves()[2]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
    }

    public function testAServiceWhosePrimaryIsTheTargetIsNeitherListedNorTouched(): void
    {
        $onTarget = $this->planService($this->gold, $this->nl, n: 1);
        $other = $this->goldService(2);
        $runId = $this->plannedAdd();
        self::assertSame([(int) $other->getId()], array_map('intval', array_column($this->moves(), 'service_id')));

        // The primary moved to the target between the listing and the claim.
        $this->em->getConnection()->update('vpn_service', ['inbound_id' => $this->nl->getId()], ['id' => $other->getId()]);
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'is_primary'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
        self::assertNull($this->nlRow($onTarget));
    }

    public function testAServiceWithoutAPrimaryIsSkipped(): void
    {
        $service = $this->goldService(1);
        $this->em->getConnection()->update('vpn_service', ['inbound_id' => null], ['id' => $service->getId()]);
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--json' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('no_primary', json_decode($output, true)['services'][0]['skip_reason']);
        self::assertSame([], $this->fake->requests);
    }

    public function testRetryNowMakesAFailedAddDueWithItsAttemptsFromZero(): void
    {
        $service = $this->goldService(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));
        $this->add(['--apply' => true]);
        self::assertSame('failed', $this->moves()[0]['state']);

        // A failed add shows on the service page, whose "Retry now" comes here (P11d).
        self::assertTrue(self::getContainer()->get(InboundMoveControls::class)->retryFailed((int) $this->runs()[0]['id'], true, (int) $service->getId())['ok']);

        self::assertSame(0, (int) $this->moves()[0]['attempts']);
        self::assertSame(1, self::getContainer()->get(InboundMoveRunner::class)->dueCount((int) $this->runs()[0]['id']));
    }

    public function testAClientNotOnItsPrimaryFailsWithNothingSent(): void
    {
        $service = $this->goldService(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [3];
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame(['GET panel/api/clients/get/tg_p1_1'], $this->fake->requestLines());
        self::assertConsoleContains('not on its primary inbound', $output);
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertNotNull($this->moves()[0]['next_attempt_at']);
        self::assertNull($this->nlRow($service));
    }

    public function testAProtocolMixIsSkippedBeforeAnyWrite(): void
    {
        $this->goldService(1);
        $this->managed($this->nl)->setProtocol('wireguard');
        $this->em->flush();
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'protocol_mix'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
    }

    public function testAMixThatArisesAfterTheRunWasCreatedReleasesTheRow(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed', ['next_attempt_at' => '2099-01-01 00:00:00']);
        $runId = $this->plannedAdd();
        $this->managed($this->nl)->setProtocol('wireguard');
        $this->em->flush();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('protocol_mix', $this->moves()[0]['skip_reason']);
        $row = $this->nlRow($service);
        self::assertSame(['failed', 'inbound not found', 1, '2099-01-01 00:00:00'], [$row['status'] ?? null, $row['last_error'] ?? null, (int) ($row['attempts'] ?? 0), $row['next_attempt_at'] ?? null]);
    }

    public function testScopeDefaultsAndTheFromInboundOption(): void
    {
        $active = $this->goldService(1);
        $suspended = $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 2);
        $expired = $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 3);
        $deleted = $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED, 4);
        $trial = $this->planService($this->gold, $this->de, n: 5, type: OrderType::TRIAL);

        [, $output] = $this->add(['--json' => true]);
        self::assertSame([$active->getId() => null, $suspended->getId() => 'suspended', $expired->getId() => 'expired', $trial->getId() => 'trial'], array_column(json_decode($output, true)['services'], 'skip_reason', 'service_id'));
        self::assertArrayNotHasKey((int) $deleted->getId(), array_column(json_decode($output, true)['services'], 'action', 'service_id'));

        [, $output] = $this->add(['--json' => true, '--include-suspended' => true, '--include-expired' => true]);
        self::assertSame(['add', 'add', 'add', 'skip'], array_column(json_decode($output, true)['services'], 'action'));

        [, $output] = $this->add(['--json' => true, '--from-inbound' => (string) $this->nl->getId()]);
        self::assertSame([], json_decode($output, true)['services']);
    }

    public function testAnOpenRunOfAnyKindRefusesASecondOne(): void
    {
        $this->goldService(1);
        $runId = $this->plannedAdd();

        [$code, $output] = $this->add(['--apply' => true]);
        self::assertSame(2, $code, $output);
        self::assertConsoleContains(sprintf('open run (#%d)', $runId), $output);

        $this->sellOn($this->gold, $this->nl);
        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(2, $code, $output);
        self::assertConsoleContains(sprintf('open run (#%d)', $runId), $output);
    }

    public function testTheFlapGuardCountsAcrossKinds(): void
    {
        $service = $this->goldService(1);
        $db = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['primary', 'add', 'add'] as $i => $kind) {
            $db->insert('inbound_move_run', ['plan_id' => $this->gold->getId(), 'kind' => $kind, 'scope' => '{}', 'status' => 'done', 'grace_hours' => 0, 'keep_old' => 0, 'created_at' => $now, 'planned_count' => 1, 'done_count' => 1, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);
            $db->insert('service_inbound_move', ['run_id' => $db->lastInsertId(), 'service_id' => $service->getId(), 'state' => 'done', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        [, $output] = $this->add(['--json' => true]);

        self::assertSame('moved_too_often', json_decode($output, true)['services'][0]['skip_reason']);
    }

    public function testAFailedRowIsClaimedAndWritten(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed', ['attempts' => 12, 'alert_state' => 'exhausted', 'next_attempt_at' => '2099-01-01 00:00:00']);
        $id = $this->nlRow($service)['id'] ?? null;

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        $row = $this->nlRow($service);
        self::assertSame([$id, 'attached', 0, null, null, null], [$row['id'] ?? null, $row['status'] ?? null, (int) ($row['attempts'] ?? -1), $row['last_error'] ?? null, $row['alert_state'] ?? null, $row['next_attempt_at'] ?? null], 'the same row, cleared');
    }

    public function testAFailedStepPutsAClaimedRowBackAsItWas(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'failed', ['next_attempt_at' => '2099-01-01 00:00:00']);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame('failed', $this->moves()[0]['state']);
        $row = $this->nlRow($service);
        self::assertSame(['failed', 'inbound not found', 1, '2099-01-01 00:00:00'], [$row['status'] ?? null, $row['last_error'] ?? null, (int) ($row['attempts'] ?? 0), $row['next_attempt_at'] ?? null]);
    }

    public function testAFreshPendingRowIsBusyNotASkip(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'pending', ['updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'last_error' => 'being attached now']);

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['planned', 0], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']]);
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertSame([], $this->panelWrites());
        self::assertSame('pending', $this->nlRow($service)['status'] ?? null);

        // The other attach finished: the next pass has nothing to do.
        $this->em->getConnection()->update('vpn_service_inbound', ['status' => 'attached'], ['service_id' => $service->getId()]);
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);
        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'already_on_target'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame('done', $this->runs()[0]['status']);
    }

    public function testAStalePendingRowIsTakenOver(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'pending', ['updated_at' => '2026-01-01 00:00:00']);

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testADetachedRowOfALiveServiceIsWrittenAgain(): void
    {
        $service = $this->goldService(1);
        $this->rowFor($service, 'detached');

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testAClientAlreadyOnThePanelGetsItsRowAndUndoLeavesItAlone(): void
    {
        $service = $this->goldService(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1, 2];
        $this->fake->requests = [];

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->panelWrites(), 'nothing sent');
        self::assertSame(['done', 'already_on_panel'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->fake->requests, 'this run did not put it there');
        self::assertConsoleContains('left alone: 1', $output);
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('done', $this->moves()[0]['state']);
    }

    public function testARetryThatFindsTheInboundOnThePanelCountsAsThisRuns(): void
    {
        $service = $this->goldService(1);
        $this->attachLandsButIsNotConfirmed(1);

        $this->add(['--apply' => true]);
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertSame([1, 2], $this->onPanel(1));
        $this->fake->clearScripts();
        $this->due();

        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', null], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']], 'an earlier try may have sent it: undo must be able to take it off');
        self::assertNotNull($this->nlRow($service));
    }

    public function testFiveFailedReadsPauseTheRunAndNothingElseChanges(): void
    {
        foreach (range(1, 6) as $n) {
            $this->goldService($n);
        }
        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Could not resolve host'), times: 100);
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame(['paused', 'panel not answering: 5 failed panel reads in a row'], [$this->runs()[0]['status'], $this->runs()[0]['pause_reason']]);
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed', 'planned'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->panelWrites());
        $paused = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::MOVE_PAUSED_TITLE)));
        self::assertCount(1, $paused);
        self::assertStringContainsString('Kind: add', $paused[0]);
    }

    public function testGivingUpAlertsOnceWithAnAddText(): void
    {
        $this->goldService(1);
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'no such inbound'));
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame('done', $this->runs()[0]['status']);
        $gaveUp = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::MOVE_GAVE_UP_TITLE)));
        self::assertCount(1, $gaveUp);
        self::assertStringContainsString('keeps the inbounds it had', $gaveUp[0]);
        self::assertStringNotContainsString('old inbound', $gaveUp[0]);
    }

    public function testThePlanLosingTheInboundPausesTheRunBeforeAnyPanelCall(): void
    {
        $service = $this->goldService(1);
        $runId = $this->plannedAdd();
        $this->sellNoMore();
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        $run = $this->runs()[0];
        self::assertSame('paused', $run['status']);
        self::assertStringContainsString('no longer sells', (string) $run['pause_reason']);
        self::assertSame([], $this->fake->requests);
        self::assertSame('planned', $this->moves()[0]['state']);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_paused'], array_column($this->audits(), 'action'));
        self::assertSame(['add', 'automatic'], [$this->audits()[1]['details']['kind'] ?? null, $this->audits()[1]['details']['by'] ?? null]);
        self::assertCount(1, array_slice($this->http->telegramTexts(), $before), 'one alert');

        // The task leaves a paused run alone: no second alert.
        self::getContainer()->get(InboundMoveScheduler::class)->run();
        self::assertCount(1, array_slice($this->http->telegramTexts(), $before));

        // Resumed with the plan as it was: it continues.
        $this->sellAlso();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNotNull($this->nlRow($service));
    }

    public function testThePausedRunCanBeCancelledAndLinksStillToRefreshDoNotKeepItStuck(): void
    {
        $one = $this->goldService(1);
        $this->goldService(2);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$one->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));
        $this->add(['--apply' => true, '--limit' => '1']);
        self::assertSame('switched', $this->moves()[0]['state']);
        $runId = (int) $this->runs()[0]['id'];
        $this->sellNoMore();

        // Only a link refresh is left: it adds nothing, so the contradiction does not stand in its way.
        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
    }

    public function testACancelledRunWithAMoveLeftIsNotBlockedByThePlan(): void
    {
        $one = $this->goldService(1);
        $this->goldService(2);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$one->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));
        $this->fake->onRequest = function (string $phase, string $method, string $path): void {
            if ('after' === $phase && str_contains($path, 'subLinks')) {
                $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', pause_reason = 'by a test'");
            }
        };
        $this->add(['--apply' => true]);
        $this->fake->onRequest = null;
        self::assertSame(['switched', 'planned'], array_column($this->moves(), 'state'));
        $runId = (int) $this->runs()[0]['id'];
        $this->sellNoMore();

        [$code, $output] = $this->command(['--cancel' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame(['switched', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame('paused', $this->runs()[0]['status'], 'open: links are still to refresh');

        [$code, $output] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame('done', $this->runs()[0]['status']);
    }

    public function testThePauseCancelAndResumeControlsTakeAnAddRun(): void
    {
        $this->goldService(1);
        $this->goldService(2);
        $runId = $this->plannedAdd();
        $controls = self::getContainer()->get(InboundMoveControls::class);

        self::assertTrue($controls->pause($runId)['ok']);
        self::assertSame(InboundMovePass::NOT_OPEN, self::getContainer()->get(InboundMoveRunner::class)->process($runId, leavePaused: true)->outcome);
        self::assertTrue($controls->resume($runId, true)['ok']);
        $cancelled = $controls->cancel($runId);
        self::assertTrue($cancelled['ok']);
        self::assertSame(2, $cancelled['cancelled']);
        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertSame([], $this->fake->requests);
        foreach ($this->audits() as $audit) {
            self::assertSame('add', $audit['details']['kind'] ?? null, $audit['action']);
        }
    }

    public function testTheTaskContinuesAnAddRun(): void
    {
        $service = $this->goldService(1);
        $this->plannedAdd();
        $scheduler = self::getContainer()->get(InboundMoveScheduler::class);

        self::assertSame(1, $scheduler->run(dryRun: true)->due);
        $task = $scheduler->run();

        self::assertSame([1, 1], [$task->runs, $task->moved]);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertNotNull($this->nlRow($service));
    }

    /** @return iterable<string, array{string, string}> */
    public static function passOff(): iterable
    {
        yield 'moves off' => [InboundMoveSettings::ENABLED, InboundMovePass::SWITCHED_OFF];
        yield 'multi-inbound off' => [MultiInboundSettings::ENABLED, InboundMovePass::SWITCHED_OFF];
    }

    #[DataProvider('passOff')]
    public function testWithASwitchOffAPassDoesNothingAndItemsStayDue(string $key, string $outcome): void
    {
        $this->goldService(1);
        $runId = $this->plannedAdd();
        $this->setting($key, 'false', 'boolean');
        $before = $this->runs();

        self::assertSame($outcome, self::getContainer()->get(InboundMoveRunner::class)->process($runId)->outcome);
        self::assertSame(MultiInboundSettings::ENABLED === $key ? 0 : 1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));
        self::assertSame(0, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs);

        self::assertSame($before, $this->runs());
        self::assertSame('planned', $this->moves()[0]['state']);
        self::assertSame([], $this->fake->requests);
    }

    public function testTheTimerOwnsTheRowAfterAnAdd(): void
    {
        $service = $this->goldService(1);
        $this->add(['--apply' => true]);
        $this->fake->requests = [];

        $run = $this->scheduled();

        self::assertSame([0, 0, 0], [$run->attached, $run->foundRemoved, $run->foundAttached]);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame([1, 2], $this->onPanel(1));

        // Removed by hand: re-attached, as the plan promises.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        self::assertSame(1, $this->scheduled()->foundRemoved);
        $this->scheduled();
        self::assertSame([1, 2], $this->onPanel(1));
    }

    public function testUndoTakesTheInboundOffAndHidesTheLinksUntilTheRefresh(): void
    {
        $services = [1 => $this->goldService(1), 2 => $this->goldService(2)];
        $before = [1 => $this->identityOf($services[1], 1), 2 => $this->identityOf($services[2], 2)];
        $this->add(['--apply' => true]);
        $runId = (int) $this->runs()[0]['id'];
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', 'undone'], array_column($this->moves(), 'state'));
        self::assertNull($this->moves()[0]['next_attempt_at']);
        foreach ($services as $n => $service) {
            self::assertSame([1], $this->onPanel($n));
            self::assertNull($this->nlRow($service), 'row deleted, not detached (the inbound can be deleted)');
            self::assertSame($before[$n], $this->identityOf($service, $n));
            $row = $this->serviceRow((int) $service->getId());
            self::assertNotNull($row['inbound_changed_at'], 'undoing an add is shaped like a remove');
            self::assertGreaterThanOrEqual((string) $row['inbound_changed_at'], (string) $row['last_access_info_synced_at'], 'links refreshed');
            self::assertCount(1, json_decode((string) $row['config_links'], true));
        }
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished', 'plan.inbounds_apply_undone'], array_column($this->audits(), 'action'));
        self::assertSame(['add', (int) $this->nl->getId(), 2], [$this->audits()[2]['details']['kind'] ?? null, $this->audits()[2]['details']['target_inbound_id'] ?? null, $this->audits()[2]['details']['undone'] ?? null]);
        self::assertConsoleContains('still sells', $output);
        self::assertSame([(int) $this->nl->getId()], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT inbound_id FROM plan_additional_inbound WHERE plan_id = ?', [$this->gold->getId()])), 'the plan is not changed');

        // Pressing it again does nothing.
        $this->fake->requests = [];
        [$code] = $this->command(['--undo' => (string) $runId]);
        self::assertSame(0, $code);
        self::assertSame([], $this->fake->requests);
    }

    public function testUndoNeedsOnlyTheMoveSwitch(): void
    {
        $this->goldService(1);
        $this->add(['--apply' => true]);
        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame([1], $this->onPanel(1));
    }

    public function testUndoOfAnOpenRunCancelsWhatDidNotStartAndCoversAFailedMove(): void
    {
        $done = $this->goldService(1);
        $failed = $this->goldService(2);
        $this->goldService(3);
        $this->fake->onRequest = function (string $phase, string $method, string $path): void {
            // Stops the pass before the third service, as --pause-run would.
            if ('after' === $phase && 'POST' === $method && str_contains($path, 'tg_p1_2/attach')) {
                $this->em->getConnection()->executeStatement("UPDATE inbound_move_run SET status = 'paused', pause_reason = 'by a test'");
            }
        };
        $this->attachLandsButIsNotConfirmed(2);
        $this->add(['--apply' => true]);
        $this->fake->onRequest = null;
        $this->fake->clearScripts();
        self::assertSame(['done', 'failed', 'planned'], array_column($this->moves(), 'state'));
        self::assertNotNull($this->moves()[1]['next_attempt_at'], 'waiting for a retry');
        self::assertSame([1, 2], $this->onPanel(2), 'the attach landed');

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', 'undone', 'cancelled'], array_column($this->moves(), 'state'), 'a failed attach may have landed: it is taken off too');
        self::assertSame([[1], [1]], [$this->onPanel(1), $this->onPanel(2)]);
        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertNull($this->nlRow($done));
        self::assertNull($this->nlRow($failed));
    }

    public function testUndoHoldsTheRowWhenThePanelFailsAndAPressAgainFinishes(): void
    {
        $service = $this->goldService(1);
        $this->add(['--apply' => true]);
        $runId = (int) $this->runs()[0]['id'];
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'locked'));

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertNull($this->moves()[0]['next_attempt_at'], 'undo has no back-off');
        $row = $this->nlRow($service);
        self::assertSame('detached', $row['status'] ?? null, 'held: the timer must not attach it again');
        self::assertStringContainsString(sprintf('run #%d', $runId), (string) ($row['last_error'] ?? ''));

        $this->fake->requests = [];
        self::assertSame(0, $this->scheduled()->attached);
        self::assertSame([], $this->attachCalls());

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('undone', $this->moves()[0]['state']);
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
    }

    public function testUndoFinishesTheLinksOnAPressAgainWhenTheRefreshFailed(): void
    {
        $service = $this->goldService(1);
        $this->add(['--apply' => true]);
        $runId = (int) $this->runs()[0]['id'];
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('undone', $this->moves()[0]['state']);
        self::assertNotNull($this->moves()[0]['next_attempt_at']);
        $this->em->clear();
        $fresh = $this->em->find($service::class, $service->getId());
        self::assertNotNull($fresh);
        self::assertTrue(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($fresh), 'NL-2\'s dead link stays hidden');

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->panelWrites());
        self::assertNull($this->moves()[0]['next_attempt_at']);
        $this->em->clear();
        $fresh = $this->em->find($service::class, $service->getId());
        self::assertNotNull($fresh);
        self::assertFalse(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($fresh));
    }

    public function testUndoLeavesAServiceWhosePrimaryIsTheTargetNow(): void
    {
        $service = $this->goldService(1);
        $this->add(['--apply' => true]);
        $this->em->getConnection()->update('vpn_service', ['inbound_id' => $this->nl->getId()], ['id' => $service->getId()]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'is_primary'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null, 'nothing was claimed');
    }

    public function testUndoPutsTheRowBackWhenTheDriverRefuses(): void
    {
        $service = $this->goldService(1);
        $this->add(['--apply' => true]);
        // By hand on the panel: the client is on NL-2 only, so a detach would leave it on nothing.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [2];
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'last_inbound'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testUndoIsRefusedForARunThatIsNotThePlansLatest(): void
    {
        $this->goldService(1);
        $this->add(['--apply' => true]);
        $first = (int) $this->runs()[0]['id'];
        $this->goldService(2);
        $this->add(['--apply' => true]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $first]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('not the plan\'s latest', $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame([], $this->fake->requests);

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[1]['id']]);
        self::assertSame(0, $code, $output);
    }

    public function testAPrimaryUndoIsRefusedOnceAnAddRunFollowedIt(): void
    {
        $this->movableService(1);
        [$code, $output] = $this->command(['--apply' => true, '--keep-old' => true]);
        self::assertSame(0, $code, $output);
        $primary = (int) $this->runs()[0]['id'];
        $this->em->getConnection()->insert('inbound_move_run', ['plan_id' => $this->gold->getId(), 'kind' => 'add', 'scope' => '{}', 'status' => 'done', 'grace_hours' => 0, 'keep_old' => 0, 'created_at' => '2026-01-01 00:00:00', 'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);

        [$code, $output] = $this->command(['--undo' => (string) $primary]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('not the plan\'s latest', $output);
        self::assertSame('switched', $this->moves()[0]['state']);
    }

    public function testAnAddGivenUpWithTheInboundOnTheClientHealsThroughALaterRun(): void
    {
        $service = $this->goldService(1);
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        $this->attachLandsButIsNotConfirmed(1);
        $this->add(['--apply' => true]);
        $this->fake->clearScripts();
        self::assertSame(['failed', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->nlRow($service), 'C on the client, no row');
        self::assertSame([1, 2], $this->onPanel(1));

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('already_on_panel', $this->moves()[1]['skip_reason']);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testAnAddLeavesARunnerOfAKindNotBuiltAlone(): void
    {
        $this->goldService(1);
        $runId = $this->plannedAdd();
        $this->em->getConnection()->update('inbound_move_run', ['kind' => 'replace'], ['id' => $runId]);

        self::assertSame(InboundMovePass::NOT_OPEN, self::getContainer()->get(InboundMoveRunner::class)->process($runId)->outcome);
    }

    public function testTheCandidatesScopeIsTheRunsScope(): void
    {
        $one = $this->goldService(1);
        $this->goldService(2);

        $runId = $this->plannedAdd(new InboundMoveScope((int) $one->getId()));

        self::assertSame(1, (int) $this->runs()[0]['planned_count']);
        self::assertSame(1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));
        self::assertSame(InboundMovePlanner::SOURCE, InboundMoveRunner::SOURCE);
    }

    /** The attach of client $n is applied, and every read after it fails: the step ends failed, not knowing. */
    private function attachLandsButIsNotConfirmed(int $n): void
    {
        $fake = $this->fake;
        $fake->script('POST', 'panel/api/clients/tg_p1_'.$n.'/attach', static function () use ($fake, $n): \Symfony\Component\HttpClient\Response\MockResponse {
            $fake->script('GET', 'panel/api/clients/get/tg_p1_'.$n, Fake3xuiPanel::networkError('Operation timed out'), 10);

            return Fake3xuiPanel::envelope(true);
        }, 1, true);
    }

    private function due(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET next_attempt_at = '2000-01-01 00:00:00' WHERE state = 'failed'");
    }

    private function scheduled(): InboundAttachRun
    {
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00'");
        $this->em->clear();

        return self::getContainer()->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
    }
}
