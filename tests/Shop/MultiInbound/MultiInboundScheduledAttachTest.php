<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\InboundAttachRun;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The scheduled retry of additional inbounds (phase M2b, docs/design/multi-inbound-plans.md 3.2): back-off and the
 * attempt limit, reconcile with the panel in both directions, a panel that is down (stopped after 5, never degraded),
 * one alert per service and kind without secrets, only active services, nothing at all while the switch is off.
 */
final class MultiInboundScheduledAttachTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private Fake3xuiPanel $fake;
    /** @var array<string, mixed> the panel's inbound 2 while it is "deleted" */
    private array $vmessOnPanel;
    private int $trojanId;
    private Plan $plan;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $trojan = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $this->trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$panel, $primary, $vmess, $trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->setting(MultiInboundSettings::ENABLED, 'true');
        $this->plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$vmess, $trojan]);
        $this->em->flush();

        // Sold while inbound 2 was missing on the panel: attached to 1 and the trojan one, inbound 2 failed.
        $this->vmessOnPanel = $this->fake->inbounds[2];
        unset($this->fake->inbounds[2]);
        $this->service = $this->sell();
        self::assertSame(['failed', 'attached'], $this->statuses());
        $this->fake->requests = [];
    }

    public function testAFailedInboundIsAttachedWhenItIsBackAndTheNextRunHasNothingToDo(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();

        $run = $this->scheduled();

        self::assertSame([1, 1, 0], [$run->services, $run->attached, $run->stillFailed]);
        self::assertSame(['attached', 'attached'], $this->statuses());
        self::assertSame([1, $this->trojanId, 2], $this->client()['inboundIds']);
        self::assertNull($this->rowsOf()[0]['next_attempt_at']);
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines(), 'never a second client');

        $this->fake->requests = [];
        $again = $this->scheduled();
        self::assertSame(0, $again->services, 'checked just now, nothing failed: not due');
        self::assertSame([], $this->fake->requests);
    }

    public function testTheRetryBacksOffAndStopsAtTheAttemptLimit(): void
    {
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '3');
        $row = $this->rowsOf()[0];
        self::assertSame([1, null], [(int) $row['attempts'], $row['next_attempt_at']], 'the attempt at the sale; the next run retries');

        $run = $this->scheduled();
        self::assertSame([1, 0, 1], [$run->services, $run->attached, $run->stillFailed]);
        $row = $this->rowsOf()[0];
        self::assertSame([2, 'failed'], [(int) $row['attempts'], $row['status']]);
        $delay = strtotime((string) $row['next_attempt_at']) - time();
        self::assertGreaterThan(9 * 60, $delay, 'the second failure waits 10 minutes');
        self::assertLessThanOrEqual(10 * 60 + 5, $delay);
        self::assertCount(1, $this->attachCalls());

        // Not due yet (and checked just now): nothing at all.
        $this->fake->requests = [];
        self::assertSame(0, $this->scheduled()->services);
        self::assertSame([], $this->fake->requests);

        $this->due();
        $this->scheduled();
        $row = $this->rowsOf()[0];
        self::assertSame(3, (int) $row['attempts']);
        self::assertNull($row['next_attempt_at'], 'attempts used up: no next one');

        $this->due();
        $this->checkedLongAgo();
        $this->fake->requests = [];
        $run = $this->scheduled();
        self::assertSame(1, $run->services, 'still compared with the panel');
        self::assertSame([], $this->attachCalls(), 'given up: the panel is only read, never written');
        self::assertSame(3, (int) $this->rowsOf()[0]['attempts']);
        self::assertSame(0, $run->stillFailed);

        // Raising the limit lets it try again.
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '4');
        $this->scheduled();
        self::assertSame(4, (int) $this->rowsOf()[0]['attempts']);
    }

    public function testOneAlertForTheFirstFailureOneWhenTheAttemptsAreUsedUpAndOneWhenItIsAttachedAgain(): void
    {
        $this->setting(MultiInboundSettings::ATTACH_MAX_ATTEMPTS, '3');
        $before = count($this->http->telegramTexts());

        $this->scheduled();
        $this->scheduled();
        $failed = $this->alerts($before, InboundAttachAlerts::FAILED_TITLE);
        self::assertCount(1, $failed, 'once per service, not once per run');
        self::assertStringContainsString('Service ID: '.$this->service->getId(), $failed[0]);
        self::assertStringContainsString('"ws"', $failed[0]);
        self::assertStringNotContainsString('"trojan"', $failed[0], 'only the inbounds that failed');

        $this->due();
        $this->scheduled();
        $this->scheduled();
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::EXHAUSTED_TITLE));
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::FAILED_TITLE));

        // Fixed on the panel, and an admin presses "Retry attach" (the scheduled retry gave up).
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        self::getContainer()->get(\App\Provisioning\Application\ServiceInboundAttacher::class)->retry($this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service'), 'admin');
        $this->scheduled();
        $this->scheduled();
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::RECOVERED_TITLE));
        self::assertNull($this->rowsOf()[0]['alert_state']);

        $this->assertNoSecrets(array_slice($this->http->telegramTexts(), $before));
    }

    public function testAClientRemovedFromAnInboundByHandOnThePanelIsFoundAndAttachedAgain(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->scheduled();
        self::assertSame(['attached', 'attached'], $this->statuses());
        $before = count($this->http->telegramTexts());

        // An admin detaches the trojan inbound in 3x-ui; it is also gone for a while.
        $key = (string) array_key_first($this->fake->clients);
        $this->fake->clients[$key]['inboundIds'] = [1, 2];
        $trojanOnPanel = $this->fake->inbounds[$this->trojanId];
        unset($this->fake->inbounds[$this->trojanId]);
        $this->checkedLongAgo();

        $run = $this->scheduled();

        self::assertSame(1, $run->foundRemoved);
        self::assertSame(['attached', 'failed'], $this->statuses());
        $row = $this->rowsOf()[1];
        self::assertSame(1, (int) $row['attempts'], 'counted again from 0, and retried at once (the inbound is gone: failed)');
        self::assertStringContainsString('not found', (string) $row['last_error']);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::FAILED_TITLE));
        self::assertCount(2, json_decode((string) $this->row('vpn_service', (int) $this->service->getId())['config_links'], true), 'the stored links follow the panel');

        // Back on the panel: the next due retry attaches it.
        $this->fake->inbounds[$this->trojanId] = $trojanOnPanel;
        $this->due();
        $run = $this->scheduled();
        self::assertSame(1, $run->attached);
        self::assertSame(['attached', 'attached'], $this->statuses());
        self::assertSame([1, 2, $this->trojanId], $this->client()['inboundIds']);
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::RECOVERED_TITLE));
    }

    public function testARemoveReattachLoopStopsAfterThreeCyclesInADayWithOneAlert(): void
    {
        // M2c F7: something on the panel keeps removing the client from the trojan inbound.
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->scheduled();
        self::assertSame(['attached', 'attached'], $this->statuses());
        $before = count($this->http->telegramTexts());
        $key = (string) array_key_first($this->fake->clients);

        for ($cycle = 1; $cycle <= InboundAttachScheduler::MAX_REATTACH_CYCLES; ++$cycle) {
            $this->fake->clients[$key]['inboundIds'] = [1, 2];
            $this->checkedLongAgo();
            $run = $this->scheduled();
            self::assertSame([1, 1], [$run->foundRemoved, $run->attached], 'cycle '.$cycle.': found removed and attached again');
            self::assertSame($cycle, (int) $this->rowsOf()[1]['reattach_count']);
        }
        $this->fake->requests = [];
        $this->fake->clients[$key]['inboundIds'] = [1, 2];
        $this->checkedLongAgo();

        $run = $this->scheduled();

        self::assertSame([1, 0], [$run->foundRemoved, $run->attached], 'the fourth removal in the window: not attached again');
        self::assertSame([], $this->attachCalls());
        $row = $this->rowsOf()[1];
        self::assertSame(['failed', MultiInboundSettings::MAX_MAX_ATTEMPTS, InboundAttachScheduler::REATTACH_STOPPED], [$row['status'], (int) $row['attempts'], $row['last_error']]);
        $alerts = $this->alerts($before, InboundAttachAlerts::EXHAUSTED_TITLE);
        self::assertCount(1, $alerts);
        self::assertStringContainsString('attached again 3 times within 24 hours', $alerts[0]);

        $this->due();
        $this->checkedLongAgo();
        $this->scheduled();
        self::assertSame([], $this->attachCalls(), 'nor at a later run, whatever its schedule');
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::EXHAUSTED_TITLE), 'alerted once');

        // "Retry attach" still works.
        $this->em->clear();
        $retry = self::getContainer()->get(\App\Provisioning\Application\ServiceInboundAttacher::class)->retry($this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service'), 'admin');
        self::assertSame(1, $retry->attached);
        self::assertSame(['attached', 'attached'], $this->statuses());
    }

    public function testRemovalsFurtherApartThanTheWindowAreAlwaysAttachedAgain(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->scheduled();
        $key = (string) array_key_first($this->fake->clients);

        for ($cycle = 1; $cycle <= InboundAttachScheduler::MAX_REATTACH_CYCLES + 2; ++$cycle) {
            // The previous cycle started more than a day ago.
            $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET reattach_since = '2000-01-01 00:00:00' WHERE reattach_since IS NOT NULL");
            $this->fake->clients[$key]['inboundIds'] = [1, 2];
            $this->checkedLongAgo();
            $run = $this->scheduled();
            self::assertSame(1, $run->attached, 'cycle '.$cycle);
            self::assertSame(1, (int) $this->rowsOf()[1]['reattach_count'], 'a new window each time');
        }
    }

    public function testAFailedInboundThePanelAlreadyHasIsMarkedAttachedWithoutAnAttachCall(): void
    {
        // Attached by hand in 3x-ui; the row's next attempt is still in the future.
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $key = (string) array_key_first($this->fake->clients);
        $this->fake->clients[$key]['inboundIds'][] = 2;

        $run = $this->scheduled();

        self::assertSame([1, 0], [$run->foundAttached, $run->attached]);
        self::assertSame(['attached', 'attached'], $this->statuses());
        $row = $this->rowsOf()[0];
        self::assertSame([1, null, null], [(int) $row['attempts'], $row['last_error'], $row['next_attempt_at']], 'no attempt counted');
        self::assertNotNull($row['attached_at']);
        self::assertSame([], $this->attachCalls());
        self::assertSame(['GET panel/api/clients/get/'.$this->service->getClientEmail()], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, '/clients/get/'))));
    }

    public function testAnAnswerWithoutThePrimaryIsTreatedAsUnreadable(): void
    {
        // M2c F6: a glitching panel lists the client on the trojan inbound only (not on the primary it runs on).
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->checkedLongAgo();
        // Its first-failure alert went out already (from setUp's sale); only what this answer would cause is measured.
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET alert_state = 'failed', alerted_at = '2000-01-01 00:00:00' WHERE status = 'failed'");
        $rows = $this->rowsOf();
        $before = count($this->http->telegramTexts());
        $email = (string) $this->service->getClientEmail();
        $this->fake->script('GET', 'panel/api/clients/get/'.$email, function () use ($email): MockResponse {
            $client = $this->fake->clients[$email];
            $client['inboundIds'] = [$this->trojanId];
            unset($client['dbId']);

            return Fake3xuiPanel::envelope(true, $client);
        });

        $run = $this->scheduled();

        self::assertSame(1, $run->panelFailures, 'counted as a panel that could not be read');
        self::assertSame($rows, $this->rowsOf(), 'no state change and no attempt');
        self::assertSame([], $this->attachCalls(), 'no attach either');
        $serviceLine = 'Service ID: '.$this->service->getId();
        self::assertSame([], array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, $serviceLine))), 'no alert');
    }

    public function testTheScheduledAttachStepNeverMarksThePanelDegraded(): void
    {
        // M2c F12: the panel answers the read (so the attach step runs) and then refuses every attach, many times over.
        for ($n = 0; $n < 6; ++$n) {
            $this->sell();
        }
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->fake->script('POST', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 100);

        $run = $this->scheduled();

        self::assertSame(0, $run->panelFailures, 'every read worked');
        self::assertGreaterThanOrEqual(7, count($this->attachCalls()), 'the attach step ran for every service and failed');
        $panel = $this->em->find(VpnPanel::class, (int) $this->service->getPanel()?->getId()) ?? self::fail('panel');
        $this->em->refresh($panel);
        self::assertFalse($panel->isDegraded());
        self::assertSame(0, $panel->getConsecutiveFailures(), 'the scheduled attach does not count towards degraded');
    }

    public function testAPanelThatIsDownIsStoppedAfterFiveServicesAndNeverMarkedDegraded(): void
    {
        for ($n = 0; $n < 6; ++$n) {
            $this->sell();
        }
        $this->due();
        $attempts = $this->attempts();
        $this->fake->requests = [];
        $this->fake->script(null, null, Fake3xuiPanel::networkError('Connection refused'), 100);

        $run = $this->scheduled();

        self::assertSame(5, $run->panelFailures);
        self::assertSame(2, $run->skipped, 'the 6th and 7th services: their panel is stopped for this run');
        self::assertCount(1, $run->stoppedPanels);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => !str_starts_with($l, 'GET panel/api/clients/get/')), 'only reads, no write while it cannot be read');
        self::assertCount(5, array_unique($this->fake->requestLines()), 'one service after the other, 5 in all');
        self::assertSame($attempts, $this->attempts(), 'no attempt used up while the panel is down');
        $panel = $this->em->find(VpnPanel::class, (int) $this->service->getPanel()?->getId()) ?? self::fail('panel');
        $this->em->refresh($panel);
        self::assertFalse($panel->isDegraded());
        self::assertSame(0, $panel->getConsecutiveFailures(), 'a bulk run does not count towards degraded');

        $this->fake->clearScripts();
        $this->fake->requests = [];
        $run = $this->scheduled();
        self::assertSame(7, $run->services, 'the next run tries again');
        self::assertSame(0, $run->panelFailures);
    }

    public function testOnlyActiveServicesAreTouched(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        foreach (['suspended', 'expired', 'deleted'] as $status) {
            $this->em->getConnection()->update('vpn_service', ['status' => $status], ['id' => $this->service->getId()]);
            $rows = $this->rowsOf();

            $run = $this->scheduled();

            self::assertSame(0, $run->services, $status);
            self::assertSame([], $this->fake->requests, $status);
            self::assertSame($rows, $this->rowsOf(), $status);
        }
    }

    public function testNothingIsReadOrChangedWhileTheSwitchIsOff(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->setting(MultiInboundSettings::ENABLED, 'false');
        $rows = $this->rowsOf();
        $texts = count($this->http->telegramTexts());

        $run = $this->scheduled();

        self::assertSame(InboundAttachRun::SWITCHED_OFF, $run->outcome);
        self::assertSame([], $this->fake->requests);
        self::assertSame($rows, $this->rowsOf());
        self::assertCount($texts, $this->http->telegramTexts(), 'no alert either');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        self::assertSame(0, $tester->execute(['--only' => 'attach-inbounds']));
        self::assertSame([], $this->fake->requests);
        self::assertSame($rows, $this->rowsOf());
    }

    public function testWithTheRetryToggleOffNothingIsRetriedButTheAdminsAreStillAlerted(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $this->setting(MultiInboundSettings::ATTACH_RETRY_ENABLED, 'false');
        $before = count($this->http->telegramTexts());

        $run = $this->scheduled();

        self::assertSame(InboundAttachRun::DISABLED, $run->outcome);
        self::assertSame([], $this->fake->requests);
        self::assertSame(['failed', 'attached'], $this->statuses());
        self::assertCount(1, $this->alerts($before, InboundAttachAlerts::FAILED_TITLE));
    }

    public function testTheCommandDryRunOnlyCounts(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $this->due();
        $rows = $this->rowsOf();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:services:attach-inbounds'));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));

        self::assertConsoleContains('services due: 1', $tester->getDisplay());
        self::assertSame([], $this->fake->requests);
        self::assertSame($rows, $this->rowsOf());

        self::assertSame(0, $tester->execute(['--pause' => '0']));
        self::assertConsoleContains('attached: 1', $tester->getDisplay());
        self::assertSame(['attached', 'attached'], $this->statuses());
        $this->assertNoSecrets([$tester->getDisplay()]);

        self::assertSame(2, $tester->execute(['--limit' => '0']));
        self::assertSame(2, $tester->execute(['--pause' => '61']));
    }

    private function scheduled(): InboundAttachRun
    {
        $this->em->clear();

        return self::getContainer()->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
    }

    private function sell(): VpnService
    {
        $plan = $this->em->find(Plan::class, $this->plan->getId()) ?? self::fail('plan');
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        return $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
    }

    /** The failed rows' next attempt is now. */
    private function due(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = ?", [VpnServiceInbound::STATUS_FAILED]);
    }

    private function checkedLongAgo(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00'");
    }

    private function setting(string $key, string $value): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $db->insert('setting', ['key_name' => $key, 'value' => $value, 'type' => in_array($value, ['true', 'false'], true) ? 'boolean' : 'integer', 'created_at' => '2026-01-01 00:00:00']);
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->service->getId()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->service->getId()]);
    }

    /**
     * @return list<string>
     */
    private function attempts(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT CONCAT(id, ":", attempts) FROM vpn_service_inbound ORDER BY id');
    }

    /**
     * @return array<string, mixed>
     */
    private function client(): array
    {
        return array_values($this->fake->clients)[0];
    }

    /**
     * @return list<string>
     */
    private function attachCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
    }

    /**
     * @return list<string>
     */
    private function alerts(int $from, string $title): array
    {
        return array_values(array_filter(array_slice($this->http->telegramTexts(), $from), static fn (string $text): bool => str_starts_with($text, $title)));
    }

    /**
     * @param list<string> $texts
     */
    private function assertNoSecrets(array $texts): void
    {
        self::assertNotSame([], $texts);
        $service = $this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service');
        foreach ($texts as $text) {
            foreach ([(string) $service->getClientEmail(), (string) $service->getClientUuid(), (string) $service->getSubId(), Fake3xuiPanel::TOKEN, 'panel.example.test', '/secret'] as $secret) {
                self::assertNotSame('', $secret);
                self::assertStringNotContainsString($secret, $text);
            }
        }
    }
}
