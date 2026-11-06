<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Entity\InboundMoveRun;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
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
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PR P11c, kind=remove from the command line and the scheduled task (docs/design/plan-inbound-add-remove.md 11.1), on the
 * fake 3x-ui: the steps of 5.2, the guards of 5.3, the plan rule and the pause of 6.3, the display guard of 6.4, the grace
 * period of 6.5, and undo (7). The races and kills are InboundMoveRemoveParallelTest; redaction is
 * InboundMoveRemoveRedactionTest.
 */
final class InboundMoveRemoveTest extends IntegrationTestCase
{
    use InboundRemoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // admin_user is not purged: linked admins left by an earlier test would also get the alerts counted here.
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->setUpRemove();
    }

    public function testARemoveTakesTheInboundOffAndChangesNothingElse(): void
    {
        $services = [1 => $this->onBoth(1), 2 => $this->onBoth(2)];
        $before = [1 => $this->identityOf($services[1], 1), 2 => $this->identityOf($services[2], 2)];
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], array_column($this->moves(), 'state'));
        foreach ($services as $n => $service) {
            self::assertSame([1], $this->onPanel($n));
            self::assertSame($before[$n], $this->identityOf($service, $n), 'UUID, subId, email, expiry, limits, usage unchanged');
            self::assertNull($this->nlRow($service), 'finding 1: the row is deleted');
            $stored = $this->serviceRow((int) $service->getId());
            self::assertCount(1, json_decode((string) $stored['config_links'], true), 'links from the panel');
            self::assertFalse($this->guardOn($service));
            self::assertNotNull($stored['inbound_changed_at']);
            self::assertSame(['GET panel/api/clients/get/tg_p1_'.$n, 'POST panel/api/clients/tg_p1_'.$n.'/detach', 'GET panel/api/clients/get/tg_p1_'.$n], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_'.$n))));
        }
        self::assertSame([], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'clients/update'))), 'the client record is never sent');

        $run = $this->runs()[0];
        self::assertSame([InboundMoveRun::KIND_REMOVE, (string) $this->nl->getId(), 0, 0, 'done'], [$run['kind'], (string) $run['target_inbound_id'], (int) $run['grace_hours'], (int) $run['keep_old'], $run['status']]);
        $move = $this->moves()[0];
        self::assertSame([(string) $this->nl->getId(), '2', null, null, null], [(string) $move['from_inbound_id'], $move['from_remote_inbound_id'], $move['to_inbound_id'], $move['to_remote_inbound_id'], $move['skip_reason']]);
        self::assertNotNull($move['detached_at']);
        self::assertSame(['plan.inbounds_apply_started', 'plan.inbounds_apply_finished'], array_column($this->audits(), 'action'));
        foreach ($this->audits() as $audit) {
            self::assertSame(['remove', (int) $this->nl->getId()], [$audit['details']['kind'] ?? null, $audit['details']['target_inbound_id'] ?? null]);
        }
        self::assertConsoleContains('Removed 2, failed 0, skipped 0, remaining 0 (of 2)', $output);
    }

    /** Finding 2: the row has left "attached" when the panel is called, and the guard is only on after the panel. */
    public function testTheRowIsClaimedBeforeThePanelIsCalled(): void
    {
        $service = $this->onBoth(1);
        $seen = [];
        $this->fake->onRequest = function (string $phase, string $method, string $path) use ($service, &$seen): void {
            if ('before' === $phase && 'POST' === $method) {
                $seen[] = [$this->nlRow($service)['status'] ?? null, $this->moves()[0]['state'], $this->guardOn($service)];
            }
        };

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([['detached', 'detaching', false]], $seen);
    }

    public function testTheInboundCanBeDeletedAfterwards(): void
    {
        $this->onBoth(1);
        $this->onBoth(2);
        $gone = $this->planService($this->gold, $this->de, n: 3);
        $this->rowFor($gone, 'detached', ['last_error' => null]);
        $this->em->getConnection()->update('vpn_service', ['status' => VpnServiceStatus::DELETED], ['id' => $gone->getId()]);
        $this->remove(['--apply' => true]);
        $id = (int) $this->nl->getId();
        $this->em->clear();

        self::getContainer()->get(VpnInboundCrudController::class)->deleteEntity($this->em, $this->em->find(VpnInbound::class, $id) ?? self::fail('inbound'));

        self::assertFalse($this->inboundExists($this->nl));
        self::assertSame(['0', null], [(string) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound'), $this->moves()[0]['from_inbound_id']]);
        self::assertNull($this->runs()[0]['target_inbound_id']);
    }

    public function testTheLinksHideUntilTheRefreshAndTheRunWaitsForIt(): void
    {
        $service = $this->onBoth(1);
        $this->em->clear();
        self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($this->em->find(VpnService::class, $service->getId()) ?? self::fail('service'), 'test');
        self::assertCount(2, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true));
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'running'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->nlRow($service));
        self::assertTrue($this->guardOn($service));
        $this->em->clear();
        self::assertTrue(self::getContainer()->get(FinalConfigLinkProvider::class)->linksPending($this->em->find(VpnService::class, $service->getId()) ?? self::fail('service')), 'D\'s link is hidden');
        self::assertConsoleContains('--resume='.$this->runs()[0]['id'], $output);

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertFalse($this->guardOn($service));
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertCount(1, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true));
        self::assertSame([], $this->panelWrites(), 'nothing detached again');
    }

    public function testADryRunWritesNothingAndShowsWhatWouldBeRemoved(): void
    {
        $plain = $this->onBoth(1);
        $notOnPanel = $this->onBoth(2);
        $this->fake->clients['tg_p1_2']['inboundIds'] = [1];
        $last = $this->onBoth(3);
        $this->fake->clients['tg_p1_3']['inboundIds'] = [2];
        $suspended = $this->onBoth(4, VpnServiceStatus::SUSPENDED);
        $noRow = $this->goldService(5);
        $elsewhere = $this->onBoth(6);
        $this->fake->clients['tg_p1_6']['inboundIds'] = [2, 3];
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--json' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->panelWrites());
        self::assertSame([], $this->runs());
        $decoded = json_decode($output, true);
        self::assertSame(['remove', (int) $this->nl->getId()], [$decoded['kind'], $decoded['target_inbound_id']]);
        $services = array_column($decoded['services'], null, 'service_id');
        self::assertArrayNotHasKey((int) $noRow->getId(), $services, 'no row: not listed');
        self::assertSame(['remove', ['DE-1', 'NL-2'], ['DE-1']], [$services[$plain->getId()]['action'], $services[$plain->getId()]['inbounds_now'], $services[$plain->getId()]['inbounds_after']]);
        self::assertSame(['remove', ['DE-1'], ['DE-1'], 'not on the panel: only the row goes'], [$services[$notOnPanel->getId()]['action'], $services[$notOnPanel->getId()]['inbounds_now'], $services[$notOnPanel->getId()]['inbounds_after'], $services[$notOnPanel->getId()]['note']]);
        self::assertStringContainsString('only inbound', (string) $services[$last->getId()]['note']);
        self::assertStringContainsString('not on its primary', (string) $services[$elsewhere->getId()]['note']);
        self::assertSame(['skip', 'suspended'], [$services[$suspended->getId()]['action'], $services[$suspended->getId()]['skip_reason']]);

        [$code, $table] = $this->remove();
        self::assertSame(0, $code, $table);
        self::assertConsoleContains('Now on panel', $table);
        self::assertConsoleContains('5 service(s): 4 would lose the inbound, 1 skipped.', $table);
        foreach ($this->secretsOf($plain) as $secret) {
            self::assertStringNotContainsString($secret, $output.$table);
        }
    }

    public function testADryRunWorksWithTheSwitchesOffAndNamesThePlanRule(): void
    {
        $this->onBoth(1);
        $this->setting(InboundMoveSettings::ENABLED, '0', 'boolean');
        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');
        $this->sellAlso();

        [$code, $output] = $this->remove();

        self::assertSame(0, $code, $output);
        self::assertConsoleContains(InboundMoveSettings::ENABLED, $output);
        self::assertConsoleContains(MultiInboundSettings::ENABLED, $output);
        self::assertConsoleContains('remove it from the plan first', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function switches(): iterable
    {
        yield 'moves' => [InboundMoveSettings::ENABLED];
        yield 'multi-inbound' => [MultiInboundSettings::ENABLED];
    }

    #[DataProvider('switches')]
    public function testApplyNeedsBothSwitches(string $key): void
    {
        $this->onBoth(1);
        $this->setting($key, 'false', 'boolean');
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains($key, $output);
        self::assertSame([], $this->runs());
        self::assertSame([], $this->fake->requests);
    }

    public function testThePlanRuleRefusesATargetStillInThePlan(): void
    {
        $this->onBoth(1);
        $this->sellAlso();

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('remove it from the plan first', $output);
        self::assertSame([], $this->runs());
    }

    public function testThePlansPrimaryIsRefused(): void
    {
        $this->onBoth(1);

        [$code, $output] = $this->command(['--kind' => 'remove', '--inbound' => (string) $this->de->getId(), '--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('never removed', $output);
        self::assertConsoleContains('--kind=primary', $output);
        self::assertSame([], $this->runs());
    }

    public function testATrialBackingPlanIsRefused(): void
    {
        $this->onBoth(1);
        $this->em->persist((new TrialPlan())->setTitle('trial')->setDurationDays(1)->setInbound($this->managed($this->de))->setBackingPlan($this->managed($this->gold)));
        $this->em->flush();

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('backs a trial', $output);
    }

    public function testAnInactiveInboundCanBeRemoved(): void
    {
        $service = $this->onBoth(1);
        $this->managed($this->nl)->setIsActive(false);
        $this->em->flush();

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertNull($this->nlRow($service));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedOptions(): iterable
    {
        yield 'from-inbound' => [['--from-inbound' => '1'], '--from-inbound'];
        yield 'keep-old' => [['--apply' => true, '--keep-old' => true], '--keep-old'];
        yield 'include-trials' => [['--include-trials' => true], '--include-trials'];
        yield 'grace too long' => [['--apply' => true, '--grace-hours' => '169'], '--grace-hours'];
        yield 'grace in a dry run' => [['--grace-hours' => '1'], '--grace-hours'];
        yield 'a named run' => [['--resume' => '1'], '--resume'];
        yield 'retire-now' => [['--retire-now' => '1'], '--retire-now'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedOptions')]
    public function testOptionsThatDoNotApplyAreRefused(array $input, string $named): void
    {
        $this->onBoth(1);
        $this->fake->requests = [];

        [$code, $output] = $this->remove($input);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains($named, $output);
        self::assertSame([], $this->runs());
        self::assertSame([], $this->fake->requests);
    }

    public function testNoInboundIsRefused(): void
    {
        [$code, $output] = $this->command(['--kind' => 'remove']);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('--kind=remove needs --inbound=<id>', $output);
    }

    public function testGuardsSkipWithZeroRequests(): void
    {
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://panel2.example.test:2053/x/')->setPlainApiToken('other-token-0123456789')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $dummy = (new VpnPanel())->setType('dummy')->setTitle('dummy');
        $this->em->persist($other);
        $this->em->persist($dummy);
        $this->em->flush();
        $onOther = $this->onBoth(1)->setPanel($other);
        $onDummy = $this->onBoth(2)->setPanel($dummy);
        $this->em->flush();
        $this->http->on('panel2.example.test', static fn () => throw new \LogicException('the other panel was called'));
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([['skipped', 'other_panel'], ['skipped', 'not_real_panel']], array_map(static fn (array $m): array => [$m['state'], $m['skip_reason']], $this->moves()));
        self::assertSame([], $this->fake->requests);
        self::assertSame('attached', $this->nlRow($onOther)['status'] ?? null, 'it stays, and the skip shows it');
        self::assertSame('attached', $this->nlRow($onDummy)['status'] ?? null);

        $legacy = $this->onBoth(3);
        $this->managed($this->modernPanel)->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();
        $this->fake->requests = [];
        [$code, $output] = $this->remove(['--apply' => true, '--service' => (string) $legacy->getId()]);
        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'not_modern'], [$this->moves()[2]['state'], $this->moves()[2]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
    }

    /** Q4: a service whose primary became D keeps it, by local id or by what the remote id records. */
    public function testAServiceWhosePrimaryIsTheInboundIsNeverTouched(): void
    {
        $service = $this->planService($this->gold, $this->nl, n: 1);
        $this->rowFor($service, 'attached');
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'is_primary'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testTheDriverRefusesThePrimaryWhenTheLocalRowsDisagree(): void
    {
        $service = $this->onBoth(1);
        // The local primary says DE-1; a run created before someone moved it: the remote id names NL-2 at the claim.
        $runId = $this->plannedRemove();
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE service_inbound_move SET from_remote_inbound_id = ? WHERE run_id = ?', ['1', $runId]);

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'is_primary'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
        self::assertSame(['attached', null], [$this->nlRow($service)['status'] ?? null, $this->nlRow($service)['checked_at'] ?? null], 'given back, unchecked');
        self::assertSame([1, 2], $this->onPanel(1));
    }

    /** 5.3: never zero inbounds, whatever the local rows say. */
    public function testTheLastInboundIsNeverRemoved(): void
    {
        $service = $this->onBoth(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [2];
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'last_inbound'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->panelWrites());
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null, 'the row is put back');
        self::assertSame([2], $this->onPanel(1));
    }

    public function testAClientNotOnItsPrimaryFailsWithNothingSentAndTheRowHeld(): void
    {
        $service = $this->onBoth(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [2, 3];
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame('failed', $this->moves()[0]['state']);
        self::assertStringContainsString('not on its primary', (string) $this->moves()[0]['last_error']);
        self::assertNotNull($this->moves()[0]['next_attempt_at']);
        self::assertSame([], $this->panelWrites());
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);
    }

    public function testAClientNoLongerOnTheInboundOnlyLosesTheRow(): void
    {
        $service = $this->onBoth(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertSame([], $this->panelWrites());
        self::assertNull($this->nlRow($service));
        self::assertConsoleContains('only the row', $output);
    }

    public function testAnInboundDeletedOnThePanelCountsOnlyOnceTheListConfirmsIt(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'inbound not found'), 2);
        $this->fake->script('GET', 'panel/api/inbounds/list', Fake3xuiPanel::networkError('Could not resolve host'));

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('failed', $this->moves()[0]['state'], 'unreadable: a failure');
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);

        $this->fake->clearScripts();
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'inbound not found'));
        unset($this->fake->inbounds[2]);
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNull($this->nlRow($service));
    }

    public function testAFailureBacksOffKeepsTheRowHeldAndTheNextTryReadsFirst(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(1, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', 1], [$move['state'], (int) $move['attempts']]);
        self::assertNotNull($move['next_attempt_at']);
        self::assertNotNull($move['detach_after']);
        $row = $this->nlRow($service);
        self::assertSame(['detached', sprintf('being removed by run #%d', $this->runs()[0]['id'])], [$row['status'] ?? null, $row['last_error'] ?? null]);
        self::assertFalse($this->guardOn($service), 'nothing changed for the customer');
        self::assertSame('running', $this->runs()[0]['status']);

        $this->due();
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 2], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']]);
        self::assertSame('GET panel/api/clients/get/tg_p1_1', $this->fake->requestLines()[0]);
        self::assertNull($this->nlRow($service));
    }

    public function testFiveFailedReadsPauseTheRunAndNothingChangesForCustomers(): void
    {
        $services = array_map(fn (int $n): VpnService => $this->onBoth($n), range(1, 6));
        $this->fake->script('GET', null, Fake3xuiPanel::networkError('Could not resolve host'), times: 100);
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame(['paused', 'panel not answering: 5 failed panel reads in a row'], [$this->runs()[0]['status'], $this->runs()[0]['pause_reason']]);
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed', 'planned'], array_column($this->moves(), 'state'));
        self::assertSame([], $this->panelWrites());
        foreach ($services as $service) {
            self::assertFalse($this->guardOn($service));
        }
        self::assertSame('attached', $this->nlRow($services[5])['status'] ?? null, 'the one not reached keeps its row');
        $paused = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::MOVE_PAUSED_TITLE)));
        self::assertCount(1, $paused);
        self::assertStringContainsString('Kind: remove', $paused[0]);

        $this->fake->clearScripts();
        $this->due();
        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(array_fill(0, 6, 'done'), array_column($this->moves(), 'state'));
        self::assertSame('done', $this->runs()[0]['status']);
    }

    public function testGivingUpAlertsOnceAndRetireNowSettlesIt(): void
    {
        $service = $this->onBoth(1);
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '1', 'number');
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame('done', $this->runs()[0]['status'], 'given up, reported');
        $gaveUp = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::MOVE_GAVE_UP_TITLE)));
        self::assertCount(1, $gaveUp);
        self::assertStringContainsString('Removing "NL-2"', $gaveUp[0]);
        self::assertStringContainsString('--undo=', $gaveUp[0]);
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null, 'R5: held until someone settles it');

        $runId = (string) $this->runs()[0]['id'];
        [$code, $output] = $this->command(['--retire-now' => $runId, '--resume' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('due for the removal of the inbound now', $output);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->nlRow($service));
    }

    public function testTheGracePeriodWaitsAndRetireNowEndsIt(): void
    {
        $service = $this->onBoth(1);
        $this->setting(InboundMoveSettings::DETACH_GRACE_HOURS, '24', 'number');
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true, '--grace-hours' => '2']);

        self::assertSame(0, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['planned', 2], [$move['state'], (int) $this->runs()[0]['grace_hours']]);
        self::assertGreaterThan((new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'), (string) $move['detach_after']);
        self::assertSame([], $this->fake->requests, 'no panel call during the grace period');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('running', $this->runs()[0]['status']);
        self::assertConsoleContains('keep the inbound until their grace period ends', $output);
        $runId = (int) $this->runs()[0]['id'];
        self::assertSame(0, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));

        [$code, $output] = $this->command(['--retire-now' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('The inbound is removed by the next pass', $output);
        self::assertSame(1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->moves()[0]['state']);
        self::assertNull($this->nlRow($service));
    }

    public function testTheGraceDefaultIsZeroWhateverTheSettingSays(): void
    {
        $service = $this->onBoth(1);
        $this->setting(InboundMoveSettings::DETACH_GRACE_HOURS, '24', 'number');

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(0, (int) $this->runs()[0]['grace_hours']);
        self::assertNull($this->nlRow($service));
    }

    public function testRetireNowForOneService(): void
    {
        $one = $this->onBoth(1);
        $two = $this->onBoth(2);
        $this->remove(['--apply' => true, '--grace-hours' => '2']);
        $runId = (string) $this->runs()[0]['id'];

        [$code, $output] = $this->command(['--retire-now' => $runId, '--resume' => $runId, '--service' => (string) $one->getId()]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'planned'], array_column($this->moves(), 'state'));
        self::assertNull($this->nlRow($one));
        self::assertSame('attached', $this->nlRow($two)['status'] ?? null);
    }

    public function testCancelDuringTheGracePeriodLeavesEveryServiceAlone(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true, '--grace-hours' => '2']);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--cancel' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('keep the inbound', $output);
        self::assertSame(['cancelled', 'cancelled'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame([], $this->fake->requests);
    }

    /** 5.2: a cancel does not cover a failed remove: its row is held, and only a read settles it. */
    public function testCancelLeavesAStartedRemoveOpen(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $runId = (string) $this->runs()[0]['id'];

        [$code, $output] = $this->command(['--cancel' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertConsoleContains('--undo='.$runId, $output);
        self::assertSame(['failed', 'running'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);

        $this->due();
        [$code] = $this->command(['--resume' => $runId]);
        self::assertSame(0, $code);
        self::assertNull($this->nlRow($service));
    }

    /** --retire-now puts a failed remove back in "planned", row held: it is not "not started". */
    public function testCancelLeavesAPlannedMoveThatHoldsItsRow(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $runId = (string) $this->runs()[0]['id'];
        $this->command(['--retire-now' => $runId]);
        self::assertSame('planned', $this->moves()[0]['state']);

        [$code, $output] = $this->command(['--cancel' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['planned', 'running'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', 'cancelled'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testThePlanSellingTheInboundAgainPausesTheRunBeforeAnyPanelCall(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->sellAlso();
        $before = count($this->http->telegramTexts());

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertStringContainsString(sprintf('sells inbound #%d again', $this->nl->getId()), (string) $this->runs()[0]['pause_reason']);
        self::assertSame([], $this->fake->requests);
        self::assertSame(['planned', 'attached'], [$this->moves()[0]['state'], $this->nlRow($service)['status'] ?? null]);
        self::assertCount(1, array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::MOVE_PAUSED_TITLE)));
        self::assertSame('plan.inbounds_apply_paused', $this->audits()[1]['action'] ?? null);

        $this->sellNoMore();
        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertNull($this->nlRow($service));
    }

    public function testATheInboundBecomingThePrimaryPausesTheRunToo(): void
    {
        $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->managed($this->gold)->setInbound($this->managed($this->nl));
        $this->em->flush();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('paused', $this->runs()[0]['status']);
        self::assertSame([], $this->fake->requests);
    }

    public function testLinksStillOwedDoNotPauseARunWhoseInboundIsBackInThePlan(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));
        $this->remove(['--apply' => true]);
        $this->sellAlso();

        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame('done', $this->runs()[0]['status']);
        self::assertFalse($this->guardOn($service));
    }

    public function testARowThatWentAwayIsSkipped(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->em->getConnection()->delete('vpn_service_inbound', ['service_id' => $service->getId()]);

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'not_attached'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
        self::assertSame([1, 2], $this->onPanel(1));
    }

    public function testAFreshPendingRowIsBusyNotASkip(): void
    {
        $service = $this->onBoth(1, rowStatus: 'pending');
        $this->em->getConnection()->update('vpn_service_inbound', ['updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['service_id' => $service->getId()]);
        $runId = $this->plannedRemove();

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['planned', 0], [$this->moves()[0]['state'], (int) $this->moves()[0]['attempts']]);
        self::assertSame('pending', $this->nlRow($service)['status'] ?? null);
        self::assertSame([], $this->fake->requests);
        self::assertSame('running', $this->runs()[0]['status']);

        $this->em->getConnection()->update('vpn_service_inbound', ['status' => 'attached'], ['service_id' => $service->getId()]);
        [$code] = $this->command(['--resume' => (string) $runId]);
        self::assertSame(0, $code);
        self::assertNull($this->nlRow($service));
    }

    public function testAStalePendingAndAFailedRowAreBothRemoved(): void
    {
        $stale = $this->onBoth(1, rowStatus: 'pending');
        $failed = $this->onBoth(2, rowStatus: 'failed');
        $this->fake->requests = [];

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(['done', 'done'], array_column($this->moves(), 'state'));
        self::assertNull($this->nlRow($stale));
        self::assertNull($this->nlRow($failed));
    }

    public function testARetryThatMeetsASkipGivesTheHeldRowBack(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $this->em->getConnection()->update('vpn_service', ['status' => VpnServiceStatus::SUSPENDED], ['id' => $service->getId()]);
        $this->due();
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--resume' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'suspended'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        $row = $this->nlRow($service);
        self::assertSame(['attached', null, null], [$row['status'] ?? null, $row['last_error'] ?? null, $row['checked_at'] ?? null]);
        self::assertSame([], $this->fake->requests);
    }

    public function testAServiceDeletedDuringTheStepIsSkipped(): void
    {
        $service = $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->fake->onRequest = function (string $phase, string $method) use ($service): void {
            if ('after' === $phase && 'POST' === $method) {
                $this->em->getConnection()->update('vpn_service', ['status' => VpnServiceStatus::DELETED], ['id' => $service->getId()]);
            }
        };

        [$code, $output] = $this->command(['--resume' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'deleted'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
    }

    public function testScopeDefaultsAndLimits(): void
    {
        $active = $this->onBoth(1);
        $suspended = $this->onBoth(2, VpnServiceStatus::SUSPENDED);
        $expired = $this->onBoth(3, VpnServiceStatus::EXPIRED);

        [$code, $output] = $this->remove(['--apply' => true, '--service' => (string) $active->getId()]);

        self::assertSame(0, $code, $output);
        self::assertCount(1, $this->moves());
        self::assertSame('attached', $this->nlRow($suspended)['status'] ?? null);

        [$code, $output] = $this->remove(['--apply' => true, '--include-suspended' => true, '--include-expired' => true]);

        self::assertSame(0, $code, $output);
        self::assertNull($this->nlRow($suspended));
        self::assertNull($this->nlRow($expired));
    }

    public function testAnOpenRunOfAnyKindRefusesASecondOne(): void
    {
        $this->onBoth(1);
        $this->plannedRemove();

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(2, $code, $output);
        self::assertCount(1, $this->runs());
    }

    public function testTheFlapGuardCountsARemove(): void
    {
        $service = $this->onBoth(1);
        $db = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['primary', 'add', 'remove'] as $kind) {
            $db->insert('inbound_move_run', ['plan_id' => $this->gold->getId(), 'kind' => $kind, 'scope' => '{}', 'status' => 'done', 'grace_hours' => 0, 'keep_old' => 0, 'created_at' => $now, 'planned_count' => 1, 'done_count' => 1, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);
            $db->insert('service_inbound_move', ['run_id' => $db->lastInsertId(), 'service_id' => $service->getId(), 'state' => 'done', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        [, $output] = $this->remove(['--json' => true]);

        self::assertSame('moved_too_often', json_decode($output, true)['services'][0]['skip_reason']);
    }

    public function testTheTaskContinuesARemoveRun(): void
    {
        $service = $this->onBoth(1);
        $this->plannedRemove();
        $scheduler = self::getContainer()->get(InboundMoveScheduler::class);

        self::assertSame(1, $scheduler->run(dryRun: true)->due);
        $task = $scheduler->run();

        self::assertSame([1, 1], [$task->runs, $task->moved]);
        self::assertSame(['done', 'done'], [$this->moves()[0]['state'], $this->runs()[0]['status']]);
        self::assertNull($this->nlRow($service));
    }

    public function testTheTaskWaitsForTheGracePeriod(): void
    {
        $service = $this->onBoth(1);
        $this->plannedRemove(graceHours: 2);
        $scheduler = self::getContainer()->get(InboundMoveScheduler::class);

        self::assertSame(0, $scheduler->run(dryRun: true)->due);
        $scheduler->run();
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);

        $this->em->getConnection()->executeStatement("UPDATE service_inbound_move SET detach_after = '2000-01-01 00:00:00'");
        self::assertSame(1, $scheduler->run(dryRun: true)->due);
        $scheduler->run();
        self::assertNull($this->nlRow($service));
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
        $this->onBoth(1);
        $runId = $this->plannedRemove();
        $this->setting($key, 'false', 'boolean');
        $before = $this->runs();

        self::assertSame($outcome, self::getContainer()->get(InboundMoveRunner::class)->process($runId)->outcome);
        self::assertSame(MultiInboundSettings::ENABLED === $key ? 0 : 1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($runId));
        self::assertSame(0, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs);

        self::assertSame($before, $this->runs());
        self::assertSame('planned', $this->moves()[0]['state']);
        self::assertSame([], $this->fake->requests);
    }

    public function testTheWebButtonsStayClosedToARemoveRun(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $before = $this->moves();

        self::assertFalse(self::getContainer()->get(InboundMoveControls::class)->retryFailed((int) $this->runs()[0]['id'], true, (int) $service->getId())['ok']);

        self::assertSame($before, $this->moves());
    }

    public function testThePauseAndResumeControlsTakeARemoveRun(): void
    {
        $this->onBoth(1);
        $this->remove(['--apply' => true, '--grace-hours' => '1']);
        $controls = self::getContainer()->get(InboundMoveControls::class);
        $runId = (int) $this->runs()[0]['id'];

        self::assertTrue($controls->pause($runId)['ok']);
        self::assertSame(0, self::getContainer()->get(InboundMoveScheduler::class)->run()->runs, 'the task leaves a paused run');
        self::assertTrue($controls->resume($runId, true)['ok']);
        self::assertSame('running', $this->runs()[0]['status']);
    }

    /** Fact 6: an attached row missing on the panel is attached again. The remove leaves the timer nothing to read. */
    public function testTheTimerNeverPutsARemovedClientBack(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $this->fake->requests = [];

        $run = $this->scheduled();

        self::assertSame([0, 0, 0], [$run->attached, $run->foundRemoved, $run->foundAttached]);
        self::assertSame([], $this->fake->requests, 'no row: not even a read');
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
    }

    public function testTheTimerLeavesAHeldRowAlone(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', static function (): MockResponse {
            return Fake3xuiPanel::envelope(false, null, 'database is locked');
        });
        $this->remove(['--apply' => true]);
        // The panel did detach, whatever it answered: the timer must not take that for a hand removal.
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1];
        $this->fake->requests = [];

        $run = $this->scheduled();

        self::assertSame([0, 0, 0], [$run->attached, $run->foundRemoved, $run->foundAttached]);
        self::assertSame([], $this->attachCalls());
        self::assertSame('detached', $this->nlRow($service)['status'] ?? null);
    }

    public function testRetryAttachDoesNotTouchAHeldRow(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $this->em->clear();
        $this->fake->requests = [];

        $retry = self::getContainer()->get(\App\Provisioning\Application\ServiceInboundAttacher::class)->retry($this->em->find(VpnService::class, $service->getId()) ?? self::fail('service'), 'test');

        self::assertSame(\App\Provisioning\Application\InboundAttachRetry::NOTHING_TO_DO, $retry->outcome);
        self::assertSame([], $this->fake->requests);
    }

    public function testATimerRunAfterAnAddAndARemoveKeepsTheAddedRow(): void
    {
        $kept = $this->panelInbound(3, 'FR-3');
        $this->fake->addHostGroup('more', [3], ['cdn2.example.com']);
        $this->em->flush();
        $service = $this->onBoth(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1, 2, 3];
        $this->rowFor($service, 'attached', ['inbound_id' => $kept->getId(), 'remote_inbound_id' => '3', 'last_error' => null, 'attempts' => 0]);

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame(0, $this->scheduled()->attached);
        self::assertSame([1, 3], $this->onPanel(1));
        self::assertSame(['attached'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()])));
    }

    public function testUndoPutsTheInboundBackOnADoneMoveAndKeepsThePlan(): void
    {
        $service = $this->onBoth(1);
        $before = $this->identityOf($service, 1);
        $this->remove(['--apply' => true]);
        $stamp = $this->serviceRow((int) $service->getId())['inbound_changed_at'];
        $runId = (string) $this->runs()[0]['id'];
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(0, $code, $output);
        $move = $this->moves()[0];
        self::assertSame(['undone', null, null], [$move['state'], $move['next_attempt_at'], $move['claimed_at']]);
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertSame($before, $this->identityOf($service, 1), 'same UUID and usage');
        $row = $this->nlRow($service);
        self::assertSame(['attached', '2', null], [$row['status'] ?? null, $row['remote_inbound_id'] ?? null, $row['checked_at'] ?? null]);
        self::assertSame(['GET panel/api/clients/get/tg_p1_1', 'POST panel/api/clients/tg_p1_1/attach', 'GET panel/api/clients/get/tg_p1_1'], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'tg_p1_1'))));
        $stored = $this->serviceRow((int) $service->getId());
        self::assertSame($stamp, $stored['inbound_changed_at'], 'an undo of a remove writes no stamp (6.4)');
        self::assertCount(2, json_decode((string) $stored['config_links'], true));
        self::assertFalse($this->guardOn($service));
        self::assertSame([], self::getContainer()->get(InboundMovePlanner::class)->planAdditionalIds((int) $this->gold->getId()), 'the plan is not changed');
        self::assertConsoleContains('The plan still does not sell this inbound', $output);
        self::assertSame('plan.inbounds_apply_undone', $this->audits()[2]['action'] ?? null);
        self::assertSame(['remove', 1], [$this->audits()[2]['details']['kind'] ?? null, $this->audits()[2]['details']['undone'] ?? null]);
        self::assertSame(0, $this->audits()[2]['details']['already_retired'] ?? null);

        // A second press does nothing, and the timer owns the row again.
        $this->fake->requests = [];
        [$code] = $this->command(['--undo' => $runId]);
        self::assertSame(0, $code);
        self::assertSame([], $this->panelWrites());
        self::assertSame(0, $this->scheduled()->foundRemoved);
    }

    public function testUndoOfAFailedMoveSettlesItWithARead(): void
    {
        $service = $this->onBoth(1);
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/detach', Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->remove(['--apply' => true]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame('undone', $this->moves()[0]['state']);
        self::assertSame([], $this->panelWrites(), 'it never left the panel: nothing to send');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        self::assertSame('cancelled', $this->runs()[0]['status']);
    }

    public function testUndoOfAnOpenRunCancelsWhatDidNotStart(): void
    {
        $done = $this->onBoth(1);
        $waiting = $this->onBoth(2);
        $runId = $this->plannedRemove();
        $this->em->getConnection()->update('service_inbound_move', ['detach_after' => '2100-01-01 00:00:00'], ['service_id' => $waiting->getId()]);
        $this->command(['--resume' => (string) $runId]);
        self::assertSame(['done', 'planned'], array_column($this->moves(), 'state'));
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['undone', 'cancelled'], array_column($this->moves(), 'state'));
        self::assertSame('cancelled', $this->runs()[0]['status']);
        self::assertSame('attached', $this->nlRow($done)['status'] ?? null);
        self::assertSame('attached', $this->nlRow($waiting)['status'] ?? null);
        self::assertConsoleContains('that keep it: 1', $output);
    }

    public function testUndoNeedsOnlyTheMoveSwitch(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $this->setting(MultiInboundSettings::ENABLED, 'false', 'boolean');

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertNotNull($this->nlRow($service));
    }

    /** @return iterable<string, array{string}> */
    public static function cannotBeUndone(): iterable
    {
        yield 'deleted in the bot' => ['deleted'];
        yield 'inactive' => ['inactive'];
    }

    #[DataProvider('cannotBeUndone')]
    public function testUndoCannotPutBackAnInboundThatIsGone(string $how): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $db = $this->em->getConnection();
        if ('deleted' === $how) {
            $db->delete('vpn_inbound', ['id' => $this->nl->getId()]);
        } else {
            $db->update('vpn_inbound', ['is_active' => 0], ['id' => $this->nl->getId()]);
        }
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(1, $code, $output);
        self::assertConsoleContains('cannot be put back', $output);
        self::assertSame('done', $this->moves()[0]['state'], 'the move stays as it was');
        self::assertSame([], $this->fake->requests);
        self::assertNull($this->nlRow($service));
    }

    public function testUndoLeavesAServiceWithALaterMoveAlone(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $db = $this->em->getConnection();
        $runId = (int) $this->runs()[0]['id'];
        // A move of this service by a run of another plan (services can change hands): this plan's rule cannot see it.
        $other = $this->createPlan(1000, $this->managed($this->de));
        $this->em->flush();
        $at = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db->insert('inbound_move_run', ['plan_id' => $other->getId(), 'kind' => 'add', 'scope' => '{}', 'status' => 'done', 'grace_hours' => 0, 'keep_old' => 0, 'created_at' => $at, 'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);
        $db->insert('service_inbound_move', ['run_id' => (int) $db->lastInsertId(), 'service_id' => $service->getId(), 'state' => 'done', 'attempts' => 1, 'created_at' => $at, 'updated_at' => $at]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'later_move'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
        self::assertNull($this->nlRow($service));
    }

    public function testUndoLeavesAServiceWhosePrimaryIsTheInboundNow(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $this->em->getConnection()->update('vpn_service', ['inbound_id' => $this->nl->getId()], ['id' => $service->getId()]);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => (string) $this->runs()[0]['id']]);

        self::assertSame(0, $code, $output);
        self::assertSame(['skipped', 'is_primary'], [$this->moves()[0]['state'], $this->moves()[0]['skip_reason']]);
        self::assertSame([], $this->fake->requests);
    }

    public function testUndoFailsCleanlyAndAPressAgainFinishes(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $runId = (string) $this->runs()[0]['id'];
        $this->fake->script('POST', 'panel/api/clients/tg_p1_1/attach', Fake3xuiPanel::envelope(false, null, 'database is locked'));

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('done', $this->moves()[0]['state'], 'back as it was');
        self::assertNull($this->moves()[0]['claimed_at']);
        self::assertNull($this->nlRow($service));
        self::assertConsoleContains('--undo='.$runId, $output);

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertSame('undone', $this->moves()[0]['state']);
        self::assertNotNull($this->nlRow($service));
    }

    public function testUndoFinishesTheLinksOnAPressAgainWhenTheRefreshFailed(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $runId = (string) $this->runs()[0]['id'];
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$service->getSubId(), Fake3xuiPanel::networkError('Could not resolve host'));

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(1, $code, $output);
        self::assertSame('undone', $this->moves()[0]['state']);
        self::assertNotNull($this->moves()[0]['next_attempt_at'], 'its links are still owed');
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--undo' => $runId]);

        self::assertSame(0, $code, $output);
        self::assertNull($this->moves()[0]['next_attempt_at']);
        self::assertSame([], $this->panelWrites());
        self::assertCount(2, json_decode((string) $this->serviceRow((int) $service->getId())['config_links'], true));
    }

    public function testUndoIsRefusedForARunThatIsNotThePlansLatest(): void
    {
        $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $first = (string) $this->runs()[0]['id'];
        $this->sellAlso();
        $this->add(['--apply' => true]);

        [$code, $output] = $this->command(['--undo' => $first]);

        self::assertSame(2, $code, $output);
        self::assertConsoleContains('not the plan\'s latest run', $output);
        self::assertSame('done', $this->moves()[0]['state']);
    }

    public function testARunThatPutsItBackWorksAsAnAdd(): void
    {
        $service = $this->onBoth(1);
        $this->remove(['--apply' => true]);
        $this->sellAlso();

        [$code, $output] = $this->add(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([1, 2], $this->onPanel(1));
        self::assertSame('attached', $this->nlRow($service)['status'] ?? null);
    }

    public function testCandidatesKeepARowsOwnPanelId(): void
    {
        $service = $this->onBoth(1);
        // The inbound was edited in the bot after the row was written: the client is on what the row recorded.
        $this->em->getConnection()->update('vpn_inbound', ['remote_inbound_id' => '77'], ['id' => $this->nl->getId()]);

        [$code, $output] = $this->remove(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame('2', $this->moves()[0]['from_remote_inbound_id']);
        self::assertSame([1], $this->onPanel(1));
        self::assertNull($this->nlRow($service));
    }

    public function testTheScopeIsFrozen(): void
    {
        $this->onBoth(1);
        $late = $this->plannedRemove(new InboundMoveScope(limit: 1));

        self::assertSame(1, self::getContainer()->get(InboundMoveRunner::class)->dueCount($late));
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
