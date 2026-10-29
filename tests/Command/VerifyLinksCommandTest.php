<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:service:verify-links (docs/design/multi-inbound-plans.md 9.6): read-only; exit 0 PASS, 1 differences listed,
 * 2 could not check. Covers the stored-vs-panel comparison, the Hosts prediction, public addresses, credentials (VLESS
 * route bytes masked), U2 (no info node) and U6 (links/{email} = subLinks), and never prints a UUID, subId or token.
 */
final class VerifyLinksCommandTest extends IntegrationTestCase
{
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_verify_1';
    private const SUB_ID = 'verifysub0123456';

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($this->panel);
        $this->em->persist($inbound);
        $this->em->flush();
        $this->fake->clients[self::EMAIL] = [
            'email' => self::EMAIL, 'id' => self::UUID, 'subId' => self::SUB_ID, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => 1,
        ];
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com:8443'], ['remark' => 'CDN', 'security' => 'tls', 'sni' => 'front.example.com']);
        $this->fake->addHostGroup('off', [1], ['off.example.com'], ['isDisabled' => true]);
        $this->service = $this->createActiveService($this->createUser('8821'), $inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($this->panel->getId(), $inbound->getId(), '1', self::UUID, self::EMAIL, self::SUB_ID))
            ->setClientUuid(self::UUID)->setClientEmail(self::EMAIL)->setSubId(self::SUB_ID);
        $this->em->flush();
    }

    public function testASnapshotEqualToThePanelPasses(): void
    {
        $this->storeThePanelsLinks();
        $before = $this->row('vpn_service', (int) $this->service->getId());

        [$code, $out] = $this->verify();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Service #'.$this->service->getId().': PASS', $out);
        self::assertStringContainsString('PASS  stored snapshot = panel subLinks: 2 stored, 2 on the panel', $out);
        self::assertStringContainsString('PASS  panel subLinks = Hosts prediction: 2 link(s) predicted, 2 given', $out);
        self::assertStringContainsString('PASS  U2', $out);
        self::assertStringContainsString('PASS  U6', $out);
        self::assertStringContainsString('U1: shuffleHost', $out, 'the manual checks are listed');
        self::assertSame($before, $this->row('vpn_service', (int) $this->service->getId()), 'nothing written');
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST')), 'only GETs');
        $this->assertNoSecret($out);
    }

    public function testAStaleSnapshotIsADifference(): void
    {
        $this->service->setConfigLinks(['vless://'.self::UUID.'@origin.example.com:443?type=tcp#old']);
        $this->em->flush();

        [$code, $out] = $this->verify();

        self::assertSame(1, $code);
        self::assertStringContainsString('DIFF  stored snapshot = panel subLinks', $out);
        self::assertStringContainsString('only in the stored snapshot: vless origin.example.com:443', $out);
        self::assertStringContainsString('only in the panel\'s subLinks: vless cdn1.example.com:443 security=tls sni=front.example.com', $out);
        self::assertStringContainsString('regenerate-config <id> --apply', $out);
        $this->assertNoSecret($out);
    }

    public function testAPanelThatDisagreesWithItsHostsIsADifference(): void
    {
        $this->storeThePanelsLinks();
        $only = 'vless://'.self::UUID.'@cdn1.example.com:443?type=tcp&security=none&sni=front.example.com#CDN';
        $this->subLinks([$only]);

        [$code, $out] = $this->verify();

        self::assertSame(1, $code);
        self::assertStringContainsString('no link for host "CDN" at cdn2.example.com:8443', $out);
        self::assertStringContainsString('security none, the host says tls', $out);
        self::assertStringContainsString('subLinks has 1 link(s), the hosts predict 2', $out);
    }

    public function testAPrivateAddressAndAForeignCredentialAreDifferences(): void
    {
        $this->subLinks([
            'vless://'.self::UUID.'@127.0.0.1:443?type=tcp&security=tls&sni=front.example.com#CDN',
            'vless://00000000-0000-4000-8000-000000000000@cdn2.example.com:8443?type=tcp&security=tls&sni=front.example.com#CDN',
        ]);

        [$code, $out] = $this->verify();

        self::assertSame(1, $code);
        self::assertStringContainsString('not reachable from the internet: vless 127.0.0.1:443', $out);
        self::assertStringContainsString('credential of another client: vless cdn2.example.com:8443', $out);
        self::assertStringNotContainsString('00000000-0000-4000', $out);
    }

    public function testAVlessRouteInTheUuidStillBelongsToTheClient(): void
    {
        $routed = '7f3c9a10-5b2e-01bb-9e1f-0a1b2c3d4e5f'; // bytes 6-7 = route 443
        $this->subLinks([
            'vless://'.$routed.'@cdn1.example.com:443?type=tcp&security=tls&sni=front.example.com#CDN',
            'vless://'.$routed.'@cdn2.example.com:8443?type=tcp&security=tls&sni=front.example.com#CDN',
        ]);

        [, $out] = $this->verify();

        self::assertStringContainsString('PASS  every link carries this client\'s credential', $out);
    }

    public function testU2AnInfoNodeOrUnusableItemIsADifference(): void
    {
        $this->storeThePanelsLinks();
        $links = json_decode((string) $this->row('vpn_service', (int) $this->service->getId())['config_links'], true);
        $this->subLinks(['socks://127.0.0.1:1080#Expires%20soon', ...$links]);

        [$code, $out] = $this->verify();

        self::assertSame(1, $code);
        self::assertStringContainsString('DIFF  U2', $out);
        self::assertStringContainsString('1 item(s) of subLinks are not usable share links', $out);
    }

    public function testU6ClientLinksThatDifferFromSubLinksAreADifference(): void
    {
        $this->storeThePanelsLinks();
        $this->fake->script('GET', 'panel/api/clients/links/'.self::EMAIL, Fake3xuiPanel::envelope(true, ['vless://'.self::UUID.'@other.example.com:443?type=tcp#x']), 3);

        [$code, $out] = $this->verify();

        self::assertSame(1, $code);
        self::assertStringContainsString('DIFF  U6: links/{email} = subLinks', $out);
        self::assertStringContainsString('only in links/{email}: vless other.example.com:443', $out);
    }

    public function testWithoutTheHostsApiThePredictionIsSkipped(): void
    {
        $this->fake->hostsApi = false;
        $this->fake->hosts = [];
        $this->storeThePanelsLinks();

        [$code, $out] = $this->verify();

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('hosts=no', $out);
        self::assertStringContainsString('SKIP  panel subLinks = Hosts prediction: hosts=no', $out);
    }

    public function testItCannotCheckALegacyPanelAFailingPanelOrAMissingService(): void
    {
        [$code, $out] = $this->verify(999999999);
        self::assertSame(2, $code);
        self::assertStringContainsString('CANNOT CHECK (service not found)', $out);

        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, Fake3xuiPanel::envelope(false, null, 'unauthorized', 401), 3);
        [$code, $out] = $this->verify();
        self::assertSame(2, $code);
        self::assertStringContainsString('CANNOT CHECK (the panel could not be read: GET panel/api/clients/subLinks/{subId}: HTTP 401', $out);
        $this->assertNoSecret($out);

        $panel = $this->em->find(VpnPanel::class, $this->panel->getId()) ?? self::fail('panel gone');
        $panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();
        [$code, $out] = $this->verify();
        self::assertSame(2, $code);
        self::assertStringContainsString('legacy: Hosts not applicable', $out);
    }

    public function testJsonOutput(): void
    {
        $this->storeThePanelsLinks();

        [$code, $out] = $this->verify(null, true);

        $report = json_decode($out, true);
        self::assertIsArray($report);
        self::assertSame(0, $code);
        self::assertSame('PASS', $report['result']);
        self::assertSame(0, $report['exitCode']);
        self::assertCount(7, $report['checks']);
        self::assertCount(4, $report['manual']);
    }

    private function storeThePanelsLinks(): void
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:service:regenerate-config'));
        $tester->execute(['serviceId' => (string) $this->service->getId(), '--apply' => true]);
        $this->em->clear();
        $this->service = $this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service gone');
        $this->fake->requests = [];
    }

    /**
     * @param list<string> $links
     */
    private function subLinks(array $links): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, Fake3xuiPanel::envelope(true, $links), 3);
        $this->fake->script('GET', 'panel/api/clients/links/'.self::EMAIL, Fake3xuiPanel::envelope(true, $links), 3);
    }

    private function assertNoSecret(string $out): void
    {
        self::assertStringNotContainsString(self::UUID, $out);
        self::assertStringNotContainsString(self::SUB_ID, $out);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $out);
        self::assertStringNotContainsString('/secret/', $out);
    }

    /**
     * @return array{int, string}
     */
    private function verify(?int $serviceId = null, bool $json = false): array
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:service:verify-links'));
        $code = $tester->execute(['serviceId' => (string) ($serviceId ?? $this->service->getId())] + ($json ? ['--json' => true] : []));

        return [$code, $tester->getDisplay()];
    }
}
