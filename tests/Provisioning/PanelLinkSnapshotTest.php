<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\AdminUser;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\PanelLinkRefresh;
use App\Provisioning\Application\PanelLinkSnapshot;
use App\Provisioning\Application\ServiceConfigRegeneration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Hosts phase H0 (docs/design/multi-inbound-plans.md 9.1): a modern 3x-ui service's links are exactly the panel's
 * clients/subLinks output. "Regenerate config" (web, Mini App, regenerator) and the web "regenerate configs of the
 * inbound" store it and never add links built from the inbound; a failed call or an empty answer keeps the stored
 * links; a shorter list is stored and reported. Legacy 3x-ui services keep their own path.
 */
final class PanelLinkSnapshotTest extends IntegrationTestCase
{
    private const UUID = '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f';
    private const EMAIL = 'tg_77_order_9';
    private const SUB_ID = 'sub0123456789abc';
    /** What the bot built from the inbound before H0 (and the generator still would): must never come back. */
    private const OLD_LINK = 'vless://'.self::UUID.'@origin.example.com:443?type=tcp&security=none#old';

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $inbound;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setSubscriptionBaseUrl('https://sub.example.com')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        // An inbound the generator can build a link from (origin.example.com): the panel's hosts must win.
        $this->inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')
            ->setHost('origin.example.com')->setPort(443)->setNetwork('tcp')->setSecurity('none');
        $this->em->persist($this->panel);
        $this->em->persist($this->inbound);
        $this->em->flush();

        $this->fake->clients[self::EMAIL] = [
            'email' => self::EMAIL, 'id' => self::UUID, 'subId' => self::SUB_ID, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 77, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => 1,
        ];
        $this->service = $this->createActiveService($this->createUser('8801'), $this->inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format($this->panel->getId(), $this->inbound->getId(), '1', self::UUID, self::EMAIL, self::SUB_ID))
            ->setClientUuid(self::UUID)->setClientEmail(self::EMAIL)->setSubId(self::SUB_ID)
            ->setConfigLinks([self::OLD_LINK])->setConfigText(self::OLD_LINK);
        $this->em->flush();
    }

    public function testTheGeneratorWouldBuildTheOldLinkSoTheTestsBelowProveSomething(): void
    {
        $generated = self::getContainer()->get(VpnAccessLinkGenerator::class)->generate($this->service);
        self::assertNotSame([], $generated['configLinks']);
        self::assertStringContainsString('origin.example.com', implode("\n", $generated['configLinks']));
    }

    public function testRegenerateStoresOneLinkPerHostAndNothingBuiltFromTheInbound(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com'], ['remark' => 'CDN', 'security' => 'tls', 'sni' => 'front.example.com']);
        $this->fake->addHostGroup('off', [1], ['off.example.com'], ['isDisabled' => true]);

        $result = $this->regenerator()->regenerate($this->service, 'test');

        self::assertSame(ServiceConfigRegeneration::REFRESHED_FROM_PANEL, $result->outcome);
        self::assertSame(2, $result->linkCount);
        self::assertSame(1, $result->previousLinkCount);
        $links = $this->storedLinks();
        self::assertCount(2, $links);
        self::assertStringContainsString('@cdn1.example.com:443', $links[0]);
        self::assertStringContainsString('@cdn2.example.com:443', $links[1]);
        self::assertStringNotContainsString('origin.example.com', implode("\n", $links), 'no link built from the inbound');
        self::assertStringNotContainsString('off.example.com', implode("\n", $links));
        $row = $this->row('vpn_service', (int) $this->service->getId());
        self::assertSame(implode("\n", $links), $row['config_text']);
        self::assertSame('https://sub.example.com/sub/'.self::SUB_ID, $row['subscription_url']);
        self::assertNotNull($row['last_access_info_synced_at']);
        self::assertNotContains('GET panel/api/clients/links/'.self::EMAIL, $this->fake->requestLines());
    }

    public function testAnInboundWithoutHostsGetsThePanelsOwnLink(): void
    {
        $this->regenerator()->regenerate($this->service, 'test');

        self::assertSame(['vless://'.self::UUID.'@panel.example.test:443?type=tcp#'.self::EMAIL], $this->storedLinks());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function failures(): iterable
    {
        yield '401' => [Fake3xuiPanel::envelope(false, null, 'unauthorized', 401)];
        yield '404 page' => [new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']])];
        yield 'timeout' => [Fake3xuiPanel::networkError('Operation timed out after 3001 milliseconds')];
        yield 'empty answer' => [Fake3xuiPanel::envelope(true, [])];
        yield 'null answer' => [Fake3xuiPanel::envelope(true, null)];
        yield 'only hostile items' => [Fake3xuiPanel::envelope(true, ['javascript://x', 'socks://127.0.0.1:1080#info'])];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailedOrEmptyAnswerKeepsTheStoredLinks(MockResponse $response): void
    {
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.self::SUB_ID, $response, 10);
        $before = $this->row('vpn_service', (int) $this->service->getId());

        $result = $this->regenerator()->regenerate($this->service, 'test');

        self::assertSame(ServiceConfigRegeneration::FAILED, $result->outcome);
        self::assertSame(1, $result->linkCount, 'the stored links are what the service still has');
        self::assertNotSame('', (string) $result->error);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, (string) $result->error);
        self::assertStringNotContainsString(self::SUB_ID, (string) $result->error);
        $after = $this->row('vpn_service', (int) $this->service->getId());
        self::assertSame($before['config_links'], $after['config_links'], 'never replaced by fewer or no links, never by self-built ones');
        self::assertSame($before['config_text'], $after['config_text']);
        self::assertSame($before['last_access_info_synced_at'], $after['last_access_info_synced_at']);
    }

    public function testAShorterListIsStoredAndReported(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com', 'cdn3.example.com']);
        $this->regenerator()->regenerate($this->service, 'test');
        self::assertCount(3, $this->storedLinks());
        $this->fake->hosts[2]['isDisabled'] = true;

        $refresh = self::getContainer()->get(PanelLinkSnapshot::class)->refresh($this->service, 'test');
        $this->em->flush();

        self::assertSame(PanelLinkRefresh::UPDATED, $refresh->outcome);
        self::assertTrue($refresh->shrank());
        self::assertCount(1, $refresh->removed());
        self::assertStringContainsString('cdn3.example.com', $refresh->removed()[0]);
        self::assertCount(2, $this->storedLinks());
    }

    public function testADryRunReadsThePanelAndWritesNothing(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com']);
        $before = $this->row('vpn_service', (int) $this->service->getId());

        $refresh = self::getContainer()->get(PanelLinkSnapshot::class)->refresh($this->service, 'test', false);
        $this->em->flush();

        self::assertSame(PanelLinkRefresh::UPDATED, $refresh->outcome);
        self::assertFalse($refresh->applied);
        self::assertSame([self::OLD_LINK], $refresh->removed());
        self::assertSame($before, $this->row('vpn_service', (int) $this->service->getId()));
    }

    public function testTheWebActionsStoreThePanelsLinksAndShowTheCountChange(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $client = $this->admin();

        $client->request('GET', '/admin/vpn-service/'.$this->service->getId().'/regenerate-config');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->followRedirect();
        self::assertStringContainsString('2 لینک (قبلاً 1)', (string) $client->getResponse()->getContent(), 'the count change is shown');
        self::assertCount(2, $this->storedLinks());

        // The inbound-wide action (a POST form with a CSRF token): same snapshot, nothing from the inbound.
        $this->setStoredLinks([self::OLD_LINK]);
        $this->pressInboundButton($client);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertCount(2, $this->storedLinks());
        self::assertStringNotContainsString('origin.example.com', implode("\n", $this->storedLinks()));
    }

    public function testTheInboundButtonNeedsAPostWithItsCsrfToken(): void
    {
        $this->fake->addHostGroup('cdn', [1], ['cdn1.example.com', 'cdn2.example.com']);
        $client = $this->admin();
        $url = '/admin/vpn-inbound/'.$this->inbound->getId().'/regenerate-service-configs';

        $client->request('GET', $url);
        self::assertSame(405, $client->getResponse()->getStatusCode(), 'a link (GET) no longer triggers panel calls');
        $client->request('POST', $url, ['_token' => 'forged']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->request('POST', $url);
        self::assertSame([], $this->fake->requests, 'no panel call without a valid token');
        self::assertSame([self::OLD_LINK], $this->storedLinks());
    }

    public function testTheInboundButtonRefusesMoreThanTenServicesAndNamesTheCommand(): void
    {
        $user = $this->createUser('8803');
        for ($i = 0; $i < 10; ++$i) {
            $this->createActiveService($user, $this->inbound);
        }
        $this->em->flush();
        $client = $this->admin();

        $this->pressInboundButton($client);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        while ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        $page = html_entity_decode((string) $client->getResponse()->getContent());
        self::assertStringContainsString('php bin/console app:inbound:regenerate-service-configs '.$this->inbound->getId().' --apply', $page);
        self::assertStringContainsString('11', $page, 'the number of services is named');
        self::assertSame([], $this->fake->requests, 'no panel call');
        self::assertSame([self::OLD_LINK], $this->storedLinks());
    }

    public function testALegacyPanelServiceKeepsItsOwnPath(): void
    {
        $this->panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();

        self::assertNull(self::getContainer()->get(PanelLinkSnapshot::class)->driverFor($this->service));
        $this->regenerator()->regenerate($this->service, 'test');
        self::assertNotContains('GET panel/api/clients/subLinks/'.self::SUB_ID, $this->fake->requestLines(), 'the legacy driver never reads subLinks');
    }

    public function testAServiceOfAnotherDriverIsNotHandled(): void
    {
        $service = $this->createActiveService($this->createUser('8802'), $this->createTestInbound());

        self::assertNull(self::getContainer()->get(PanelLinkSnapshot::class)->driverFor($service));
    }

    private function regenerator(): ServiceConfigRegenerator
    {
        return self::getContainer()->get(ServiceConfigRegenerator::class);
    }

    /**
     * @return list<string>
     */
    private function storedLinks(): array
    {
        return json_decode((string) $this->row('vpn_service', (int) $this->service->getId())['config_links'], true);
    }

    /**
     * @param list<string> $links
     */
    private function setStoredLinks(array $links): void
    {
        $this->em->getConnection()->update('vpn_service', ['config_links' => json_encode($links), 'config_text' => implode("\n", $links)], ['id' => $this->service->getId()]);
        $this->em->clear();
    }

    /** Submits the "regenerate service configs" form of the inbound, as the inbound list renders it. */
    private function pressInboundButton(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/admin/vpn-panel/'.$this->panel->getId().'/inbounds');
        if ($client->getResponse()->isRedirect()) {
            $crawler = $client->followRedirect();
        }
        $form = $crawler->filter('form[action*="/vpn-inbound/'.$this->inbound->getId().'/regenerate-service-configs"]');
        self::assertCount(1, $form, 'the inbound list renders the button as a POST form');
        $client->submit($form->form());
    }

    private function admin(): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('hosts-h0-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
