<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Panels in the Mini App admin (PANELS_MANAGE, super admin only): health, last check, active switch, subscription URL
 * check, plan counts, the connection test (PanelConnectionTester against a fake 3x-ui), the read-only inbound list.
 * No address, path, token or password of a panel or inbound is ever in a response.
 */
final class MiniAppAdminPanelsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const SECRETS = ['panel.example.test', '/secret/', Fake3xuiPanel::TOKEN, Fake3xuiPanel::PASSWORD, 'sub-secret.example', 'edge-secret.example', 'pbk-secret-key', 'short-secret-id'];

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Frankfurt')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')
            ->setPlainApiToken(Fake3xuiPanel::TOKEN)->setUsername(Fake3xuiPanel::USERNAME)->setPlainPassword(Fake3xuiPanel::PASSWORD)
            ->setSubscriptionBaseUrl('http://127.0.0.1:2096');
        $inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('7')->setTitle('Reality 443')->setProtocol('vless')->setNetwork('tcp')->setSecurity('reality')
            ->setHost('edge-secret.example')->setPort(443)->setPublicKey('pbk-secret-key')->setShortId('short-secret-id');
        $idle = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('8')->setTitle('Old WS')->setIsActive(false);
        $this->em->persist($this->panel);
        $this->em->persist($inbound);
        $this->em->persist($idle);
        $this->createPlan(90000, $inbound);
        $this->createPlan(120000, $inbound);
        $this->em->flush();
        $this->startMiniAppClient();
    }

    public function testTheListShowsHealthChecksAndPlanCountsButNoAddress(): void
    {
        $session = $this->authenticate(self::SUPER);
        foreach (['', '?lang=en'] as $lang) {
            $html = $this->appGet('/app/admin/panels'.$lang, $session);
            self::assertSame(200, $this->httpStatus());
            self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html));
            $this->assertNoSecret($html);
        }
        $row = (new Crawler($html))->filter('[data-panel-id="'.$this->panel->getId().'"]');
        self::assertSame('untested', $row->attr('data-health'));
        self::assertSame('2', trim($row->filter('[data-plan-count]')->text()));
        self::assertSame('not_public', $row->filter('[data-subscription-check]')->attr('data-subscription-check'), 'a loopback subscription URL is flagged, without showing it');
        self::assertSame('1', $row->attr('data-active'));
    }

    public function testTheConnectionTestReportsWithoutTheAddressOrToken(): void
    {
        $session = $this->authenticate(self::SUPER);
        $answer = $this->appPostJson('/app/api/admin/panels/'.$this->panel->getId().'/test', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertTrue($answer['report']['ok']);
        self::assertSame(3, $answer['report']['inbounds'], 'the fake 3x-ui has three inbounds');
        $this->assertNoSecret((string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame('ok', $this->em->find(VpnPanel::class, $this->panel->getId())?->getProbeStatus(), 'the probe is stored, as on the web');
        $row = (new Crawler($this->appGet('/app/admin/panels', $session)))->filter('[data-panel-id="'.$this->panel->getId().'"]');
        self::assertSame('connected', $row->attr('data-health'));

        $this->fake->tokenRevoked = true;
        $answer = $this->appPostJson('/app/api/admin/panels/'.$this->panel->getId().'/test', $session, [], self::newKey());
        self::assertFalse($answer['report']['ok']);
        $translator = self::getContainer()->get('translator');
        self::assertSame($translator->trans('panel_connection.failure.auth_rejected.title', [], 'admin', 'fa'), $answer['report']['failure']);
        self::assertSame($translator->trans('panel_connection.failure.auth_rejected.fix', [], 'admin', 'fa'), $answer['report']['fix']);
        $this->assertNoSecret((string) $this->client->getResponse()->getContent());
        self::assertSame(['vpn_panel.test', 'vpn_panel.test'], array_column(array_filter($this->auditRows(), static fn (array $r): bool => 'miniapp' === $r['source']), 'action'));
    }

    public function testTheActiveSwitchIsConfirmedAndAudited(): void
    {
        $session = $this->authenticate(self::SUPER);
        $this->confirmedPost('/app/api/admin/panels/'.$this->panel->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertFalse($this->em->find(VpnPanel::class, $this->panel->getId())?->isActive());
        $this->confirmedPost('/app/api/admin/panels/'.$this->panel->getId().'/deactivate', $session);
        self::assertSame(409, $this->httpStatus(), 'already inactive');
        $this->confirmedPost('/app/api/admin/panels/'.$this->panel->getId().'/activate', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['vpn_panel.deactivate', 'vpn_panel.activate'], array_column(array_filter($this->auditRows(), static fn (array $r): bool => str_starts_with((string) $r['action'], 'vpn_panel.')), 'action'));
    }

    public function testTheInboundsAreReadOnlyWithTheirAttachState(): void
    {
        $session = $this->authenticate(self::SUPER);
        $html = $this->appGet('/app/admin/panels/'.$this->panel->getId().'?lang=en', $session);
        self::assertSame(200, $this->httpStatus());
        $this->assertNoSecret($html);
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html));
        $page = new Crawler($html);
        $inbounds = $page->filter('[data-inbound]')->each(static fn (Crawler $c): array => [trim($c->filter('.title')->text()), (string) $c->attr('data-attached'), trim($c->filter('[data-plan-count]')->text())]);
        self::assertSame([['Reality 443', '1', '2'], ['Old WS', '0', '0']], $inbounds);
        self::assertCount(0, $page->filter('form, input'), 'nothing to edit here');
        self::assertCount(1, $page->filter('[data-web-only]'), 'adding or editing a panel stays on the web');
    }

    public function testAnAdminWithoutPanelsManageIsRefused(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $this->appGet('/app/admin/panels', $session);
        self::assertSame(403, $this->httpStatus());
        $this->appPostJson('/app/api/admin/panels/'.$this->panel->getId().'/test', $session, [], self::newKey());
        self::assertSame(403, $this->httpStatus());
        self::assertCount(0, $this->fake->requestLines(), 'the panel was never contacted');
    }

    private function assertNoSecret(string $body): void
    {
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
    }
}
