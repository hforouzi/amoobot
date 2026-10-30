<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:service:regenerate-config and app:inbound:regenerate-service-configs (Hosts phase H0): a dry run is the default
 * and writes nothing, --apply stores the panel's subLinks (never links built from the inbound), a failing panel call
 * keeps the stored links and is reported, the bulk command works in batches and resumes with --after-id, and no output
 * shows a UUID, the token or the subscription id.
 */
final class RegenerateLinksCommandsTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

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
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($this->panel);
        $this->em->persist($this->inbound);
        $this->em->flush();
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $user = $this->createUser('8811');
        foreach ([1, 2, 3] as $n) {
            $this->services[] = $this->service($user, $n);
        }
    }

    public function testTheSingleCommandIsADryRunByDefault(): void
    {
        $service = $this->services[0];
        $before = $this->row('vpn_service', (int) $service->getId());

        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId()]);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertConsoleContains('would update: 1 -> 2 links', $out);
        self::assertConsoleContains('+ vless cdn1.example.com:443', $out);
        self::assertConsoleContains('+ vless cdn2.example.com:443', $out);
        self::assertConsoleContains('- vless origin.example.com:443 #old', $out);
        self::assertConsoleContains('Nothing was written', $out);
        self::assertSame($before, $this->row('vpn_service', (int) $service->getId()));
        $this->assertNoSecret($out, 1);
    }

    public function testTheSingleCommandAppliesThePanelsLinks(): void
    {
        $service = $this->services[0];

        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId(), '--apply' => true]);

        self::assertSame(Command::SUCCESS, $code, $out);
        $links = $this->links($service);
        self::assertCount(2, $links);
        self::assertStringNotContainsString('origin.example.com', implode("\n", $links));
        self::assertConsoleContains('2 link(s) stored (source: panel)', $out);
    }

    public function testApplyAndDryRunTogetherAreRefused(): void
    {
        [$code] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $this->services[0]->getId(), '--apply' => true, '--dry-run' => true]);

        self::assertSame(Command::INVALID, $code);
    }

    public function testAFailedPanelCallKeepsTheLinksAndFails(): void
    {
        $service = $this->services[0];
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->subId(1), Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 5);
        $before = $this->row('vpn_service', (int) $service->getId());

        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId(), '--apply' => true]);

        self::assertSame(Command::FAILURE, $code);
        self::assertConsoleContains('FAILED, stored links kept', $out);
        self::assertConsoleContains('HTTP 401', $out);
        self::assertSame($before, $this->row('vpn_service', (int) $service->getId()));
        $this->assertNoSecret($out, 1);
    }

    public function testAnEmptyAnswerKeepsTheLinks(): void
    {
        $service = $this->services[0];
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->subId(1), Fake3xuiPanel::envelope(true, []), 5);
        $before = $this->row('vpn_service', (int) $service->getId());

        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId(), '--apply' => true]);

        self::assertSame(Command::FAILURE, $code);
        self::assertConsoleContains('kept (panel gave no links)', $out);
        self::assertSame($before, $this->row('vpn_service', (int) $service->getId()));
    }

    public function testTheBulkCommandIsADryRunByDefaultForEveryService(): void
    {
        $before = array_map(fn (VpnService $s): array => $this->row('vpn_service', (int) $s->getId()), $this->services);

        [$code, $out] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--batch-size' => '2']);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertSame(3, self::consoleCount($out, 'would update: 1 -> 2 links'));
        self::assertSame(2, self::consoleCount($out, 'batch done'), 'batches of 2: 2 + 1');
        self::assertConsoleContains('last processed id '.$this->services[2]->getId(), $out);
        self::assertConsoleContains('Dry run: nothing was written', $out);
        foreach ($this->services as $i => $service) {
            self::assertSame($before[$i], $this->row('vpn_service', (int) $service->getId()));
        }
        $this->assertNoSecret($out, 3);
    }

    public function testTheBulkCommandSkipsAFailingServiceAndResumes(): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->subId(2), Fake3xuiPanel::networkError('Operation timed out after 3001 milliseconds'), 10);
        $failing = $this->row('vpn_service', (int) $this->services[1]->getId());

        [$code, $out] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--apply' => true, '--batch-size' => '1']);

        self::assertSame(Command::FAILURE, $code, 'a failure is reported');
        self::assertCount(2, $this->links($this->services[0]));
        self::assertSame($failing, $this->row('vpn_service', (int) $this->services[1]->getId()), 'the failing service keeps its links');
        self::assertCount(2, $this->links($this->services[2]), 'the run went on after the failure');
        self::assertSame(3, self::consoleCount($out, 'batch done'));
        self::assertConsoleContains('failed (stored links kept): 1', $out);

        // Resume after the first service: only the later ones are processed.
        [, $resumed] = $this->runCommand('app:inbound:regenerate-service-configs', ['inboundId' => (string) $this->inbound->getId(), '--after-id' => (string) $this->services[0]->getId()]);
        self::assertStringNotContainsString('service #'.$this->services[0]->getId().' ', $resumed);
        self::assertStringContainsString('service #'.$this->services[2]->getId().' ', $resumed);
        self::assertStringContainsString('services: 2', $resumed);
    }

    public function testADryRunUsesTheSubIdRecoveredFromTheRemoteIdLikeApply(): void
    {
        $service = $this->services[0];
        $this->em->getConnection()->update('vpn_service', ['sub_id' => null], ['id' => $service->getId()]);
        $before = $this->row('vpn_service', (int) $service->getId());

        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId()]);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertConsoleContains('subId recovered from remoteId (used for this dry run, not stored)', $out);
        self::assertContains('GET panel/api/clients/subLinks/'.$this->subId(1), $this->fake->requestLines(), 'the same endpoint as --apply');
        self::assertNotContains('GET panel/api/clients/links/tg_cmd_1', $this->fake->requestLines());
        self::assertSame($before, $this->row('vpn_service', (int) $service->getId()), 'nothing written');
        $this->assertNoSecret($out, 1);

        $this->fake->requests = [];
        [$code, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId(), '--apply' => true]);

        self::assertSame(Command::SUCCESS, $code, $out);
        self::assertContains('GET panel/api/clients/subLinks/'.$this->subId(1), $this->fake->requestLines());
        self::assertSame($this->subId(1), $this->row('vpn_service', (int) $service->getId())['sub_id'], '--apply stores the recovered subId');
        self::assertCount(2, $this->links($service));
    }

    public function testALegacyPanelServiceIsPreviewedThroughTheLegacyDriverWithoutWriting(): void
    {
        $this->panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();
        $service = $this->services[0];
        $before = $this->row('vpn_service', (int) $service->getId());

        [, $out] = $this->runCommand('app:service:regenerate-config', ['serviceId' => (string) $service->getId()]);

        self::assertConsoleContains('[legacy]', $out);
        self::assertSame($before, $this->row('vpn_service', (int) $service->getId()));
        self::assertNotContains('GET panel/api/clients/subLinks/'.$this->subId(1), $this->fake->requestLines());
    }

    private function service(\App\Entity\User $user, int $n): VpnService
    {
        $uuid = sprintf('%08d-2222-4333-8444-555555555555', $n);
        $email = 'tg_cmd_'.$n;
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
        return 'cmdsub'.str_repeat((string) $n, 10);
    }

    /**
     * @return list<string>
     */
    private function links(VpnService $service): array
    {
        return json_decode((string) $this->row('vpn_service', (int) $service->getId())['config_links'], true);
    }

    private function assertNoSecret(string $out, int $services): void
    {
        self::assertConsoleNotContains(Fake3xuiPanel::TOKEN, $out);
        self::assertConsoleNotContains('/secret/', $out);
        for ($n = 1; $n <= $services; ++$n) {
            self::assertConsoleNotContains(sprintf('%08d-2222', $n), $out, 'no UUID');
            self::assertConsoleNotContains($this->subId($n), $out, 'no subId');
        }
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
