<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bulk link runs spare the panel (F5): app:inbound:regenerate-service-configs and app:panel:sync-inbounds
 * --regenerate-configs stop after 5 failed services in a row, pause between batches, and never mark the panel
 * "degraded" (which stops its sales); sync-inbounds --regenerate-configs is a dry run unless --apply.
 */
final class BulkRegenerateSafetyTest extends IntegrationTestCase
{
    private const OLD = '@origin.example.com:443?type=tcp&security=none#old';

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $inbound;
    /** @var list<VpnService> */
    private array $services = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setUsername(Fake3xuiPanel::USERNAME)->setPlainPassword(Fake3xuiPanel::PASSWORD)
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        $this->inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($this->panel);
        $this->em->persist($this->inbound);
        $this->em->flush();
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $user = $this->createUser('8821');
        for ($n = 1; $n <= 7; ++$n) {
            $this->services[] = $this->service($user, $n);
        }
    }

    public function testTheBulkCommandStopsAfterFiveFailuresInARowAndNeverDegradesThePanel(): void
    {
        $this->fake->script('GET', null, Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 100);

        [$code, $out] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--apply' => true, '--pause' => '0']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('Stopped: 5 services in a row failed', $out);
        self::assertStringContainsString('resume with --after-id='.$this->services[4]->getId(), $out);
        self::assertCount(5, $this->subLinksCalls(), 'the 6th and 7th service were not asked');
        $this->assertPanelHealthy();
        foreach ($this->services as $i => $service) {
            self::assertStringContainsString(self::OLD, (string) $this->row('vpn_service', (int) $service->getId())['config_links'], 'service '.$i.' kept its links');
        }
    }

    public function testASingleRegenerateStillCountsTowardsDegradedSoTheBulkFlagIsWhatSparesIt(): void
    {
        $this->fake->script('GET', null, Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 100);
        foreach (array_slice($this->services, 0, 3) as $service) {
            self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($this->em->find(VpnService::class, $service->getId()), 'test');
        }
        $this->em->flush();

        self::assertGreaterThanOrEqual(3, (int) $this->row('vpn_panel', (int) $this->panel->getId())['consecutive_failures']);
    }

    public function testFailuresThatAreNotInARowDoNotStopTheRun(): void
    {
        foreach ([1, 2, 3, 4, 6, 7] as $n) {
            $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->subId($n), Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 1);
        }

        [, $out] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--pause' => '0']);

        self::assertStringNotContainsString('Stopped', $out);
        self::assertStringContainsString('services: 7', $out);
        self::assertStringContainsString('failed (stored links kept): 6', $out);
    }

    public function testThePauseSeparatesBatches(): void
    {
        $started = microtime(true);
        [$code, $out] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--batch-size' => '2', '--pause' => '0.25']);
        $elapsed = microtime(true) - $started;

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertSame(4, substr_count($out, 'batch done'), '7 services in batches of 2');
        self::assertGreaterThanOrEqual(0.75, $elapsed, 'three full batches are each followed by a 0.25 s pause');
    }

    public function testSyncRegenerateIsADryRunUnlessApply(): void
    {
        $before = array_map(fn (VpnService $s): array => $this->row('vpn_service', (int) $s->getId()), $this->services);

        [$code, $out] = $this->runCommand('app:panel:sync-inbounds', ['panelId' => (string) $this->panel->getId(), '--regenerate-configs' => true, '--batch-size' => '3', '--pause' => '0']);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertSame(7, substr_count($out, 'would update: 1 -> 2 links'));
        self::assertStringContainsString('Dry run: no service was written', $out);
        foreach ($this->services as $i => $service) {
            $row = $this->row('vpn_service', (int) $service->getId());
            self::assertSame($before[$i]['config_links'], $row['config_links']);
            self::assertSame($before[$i]['last_access_info_synced_at'], $row['last_access_info_synced_at']);
        }

        [$code, $out] = $this->runCommand('app:panel:sync-inbounds', ['panelId' => (string) $this->panel->getId(), '--regenerate-configs' => true, '--apply' => true, '--pause' => '0']);

        self::assertSame(Command::SUCCESS, $code, $out);
        foreach ($this->services as $service) {
            $links = json_decode((string) $this->row('vpn_service', (int) $service->getId())['config_links'], true);
            self::assertCount(2, $links);
            self::assertStringNotContainsString('origin.example.com', implode("\n", $links));
        }
    }

    public function testSyncStopsAfterFiveFailuresAndNeverDegradesThePanel(): void
    {
        foreach (range(1, 7) as $n) {
            $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->subId($n), Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 1);
        }

        [$code, $out] = $this->runCommand('app:panel:sync-inbounds', ['panelId' => (string) $this->panel->getId(), '--regenerate-configs' => true, '--apply' => true, '--pause' => '0']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('Stopped: 5 services in a row failed', $out);
        self::assertCount(5, $this->subLinksCalls());
        $this->assertPanelHealthy();
    }

    public function testApplyWithoutRegenerateConfigsIsRefused(): void
    {
        [$code] = $this->runCommand('app:panel:sync-inbounds', ['panelId' => (string) $this->panel->getId(), '--apply' => true]);

        self::assertSame(Command::INVALID, $code);
    }

    private function assertPanelHealthy(): void
    {
        $row = $this->row('vpn_panel', (int) $this->panel->getId());
        self::assertSame(0, (int) $row['consecutive_failures'], 'a bulk run never counts towards "degraded"');
        self::assertNull($row['degraded_since']);
    }

    /** @return list<string> */
    private function subLinksCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'clients/subLinks/')));
    }

    private function service(User $user, int $n): VpnService
    {
        $uuid = sprintf('%08d-2222-4333-8444-555555555555', $n);
        $email = 'tg_bulk_'.$n;
        $this->fake->clients[$email] = [
            'email' => $email, 'id' => $uuid, 'subId' => $this->subId($n), 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => $n,
        ];
        $service = $this->createActiveService($user, $this->inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($this->panel->getId(), $this->inbound->getId(), '1', $uuid, $email, $this->subId($n)))
            ->setClientUuid($uuid)->setClientEmail($email)->setSubId($this->subId($n))
            ->setConfigLinks(['vless://'.$uuid.self::OLD])->setConfigText('vless://'.$uuid.self::OLD);
        $this->em->flush();

        return $service;
    }

    private function subId(int $n): string
    {
        return 'bulksub'.str_repeat((string) $n, 9);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function runCommand(string $name, array $input): array
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find($name));
        $code = $tester->execute($input);

        return [$code, $tester->getDisplay()];
    }
}
