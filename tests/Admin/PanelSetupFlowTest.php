<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shared\Infrastructure\SecretCipher;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The guided panel setup in the admin, against the fake 3x-ui (through the test HttpClient): URL + token only, the
 * connection report, inbound selection, plan mapping, validation, the modern-mode refusal in the classic form, and the
 * token never rendered back.
 */
final class PanelSetupFlowTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private Fake3xuiPanel $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $admin = (new AdminUser())->setEmail('panels@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($admin, 'main');
    }

    public function testTheWholeGuidedFlow(): void
    {
        $crawler = $this->client->request('GET', '/admin/panel-setup/new');
        $this->assertResponseIsSuccessfulHere();
        self::assertCount(0, $crawler->filter('input[name*="username"], input[name*="password"]'), 'no username/password in the guided form');
        self::assertStringContainsString('Settings → Security → API Token', (string) $this->client->getResponse()->getContent());

        $form = $crawler->filter('form')->form([
            'panel_connection[title]' => 'Main panel',
            'panel_connection[baseUrl]' => Fake3xuiPanel::ORIGIN.'/secret/',
            'panel_connection[plainApiToken]' => Fake3xuiPanel::TOKEN,
        ]);
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), $this->bodyExcerpt());

        $panel = $this->em->getRepository(VpnPanel::class)->findOneBy(['title' => 'Main panel']);
        self::assertInstanceOf(VpnPanel::class, $panel);
        $row = $this->row('vpn_panel', (int) $panel->getId());
        self::assertStringStartsWith(SecretCipher::PREFIX, (string) $row['api_token']);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, (string) json_encode($row));
        self::assertSame('sanaei_3xui', $row['type']);
        self::assertSame('auto', $row['sanaei_integration_mode']);

        $crawler = $this->client->followRedirect();
        $this->assertNoTokenInPage();
        self::assertStringContainsString('••••FAKE', (string) $this->client->getResponse()->getContent(), 'masked: last 4 characters only');

        // Test connection.
        $this->client->submit($crawler->filter('form[action$="/test"]')->form());
        $this->assertResponseIsSuccessfulHere();
        $this->assertNoTokenInPage();
        $report = $this->client->getCrawler()->filter('#connection-report')->text();
        self::assertStringContainsString($this->trans('panel_connection.report.ok_modern'), $report);
        self::assertStringContainsString($this->trans('panel_connection.tls.verified'), $report);
        self::assertMatchesRegularExpression('/\d+ ms/', $report);
        self::assertStringContainsString('3', $report, 'three inbounds');
        $this->em->clear();
        $panel = $this->em->find(VpnPanel::class, $panel->getId());
        self::assertInstanceOf(VpnPanel::class, $panel);
        self::assertTrue($panel->isModern());
        self::assertSame('ok', $panel->getProbeStatus());

        // Step 2: inbounds (the disabled one cannot be chosen).
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/inbounds');
        $this->assertResponseIsSuccessfulHere();
        self::assertCount(3, $crawler->filter('input[name="inbounds[]"]'));
        self::assertCount(1, $crawler->filter('input[name="inbounds[]"][disabled]'));
        $form = $crawler->filter('form')->form();
        $form['inbounds'][0]->tick();
        $form['inbounds'][1]->tick();
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $inbounds = $this->em->getRepository(VpnInbound::class)->findBy(['panel' => $panel, 'isActive' => true]);
        self::assertSame(['1', '2'], array_map(static fn (VpnInbound $i): string => $i->getRemoteInboundId(), $inbounds));
        self::assertSame('vless', $inbounds[0]->getProtocol());
        self::assertSame(443, $inbounds[0]->getPort());
        self::assertSame('xtls-rprx-vision', $inbounds[0]->getFlow(), 'flow-capable VLESS inbound');

        // Step 3: plans.
        $plan = (new Plan())->setTitle('Gold')->setPrice(100)->setDurationDays(30);
        $this->em->persist($plan);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/plans');
        $this->assertResponseIsSuccessfulHere();
        $form = $crawler->filter('form')->form();
        $this->choice($form, 'plan['.$plan->getId().']')->select((string) $inbounds[1]->getId());
        $this->client->submit($form);
        $this->em->clear();
        self::assertSame($inbounds[1]->getId(), $this->em->find(Plan::class, $plan->getId())?->getInbound()?->getId());
    }

    public function testAFailureIsExplainedWithItsFix(): void
    {
        $panel = $this->panel();
        $this->fake->tokenRevoked = true;
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId());
        $this->client->submit($crawler->filter('form[action$="/test"]')->form());

        $report = $this->client->getCrawler()->filter('#connection-report')->text();
        self::assertStringContainsString($this->trans('panel_connection.failure.auth_rejected.title'), $report);
        self::assertStringContainsString($this->trans('panel_connection.failure.auth_rejected.fix'), $report);
        $this->assertNoTokenInPage();
    }

    public function testThePanelsAnswerIsShownEscaped(): void
    {
        $panel = $this->panel();
        $this->fake->script(null, null, Fake3xuiPanel::envelope(false, null, '<img src=x onerror=alert(1)>'));
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId());
        $this->client->submit($crawler->filter('form[action$="/test"]')->form());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x onerror', $html);
        self::assertStringContainsString('&lt;img src=x onerror', $html);
    }

    public function testValidation(): void
    {
        $cases = [
            ['http://203.0.113.7:2053/', Fake3xuiPanel::TOKEN, '', 'panel_connection.validation.url_plain_http'],
            ['panel.example.test:2053', Fake3xuiPanel::TOKEN, '', 'panel_connection.validation.url_invalid'],
            [Fake3xuiPanel::ORIGIN.'/secret/', '', '', 'panel_connection.validation.token_required'],
            [Fake3xuiPanel::ORIGIN.'/secret/', Fake3xuiPanel::TOKEN, 'AA:BB', 'panel_connection.validation.tls_pin'],
        ];
        foreach ($cases as [$url, $token, $pin, $error]) {
            $crawler = $this->client->request('GET', '/admin/panel-setup/new');
            $this->client->submit($crawler->filter('form')->form([
                'panel_connection[title]' => 'v',
                'panel_connection[baseUrl]' => $url,
                'panel_connection[plainApiToken]' => $token,
                'panel_connection[tlsPin]' => $pin,
            ]));
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $error);
            self::assertStringContainsString(htmlspecialchars($this->trans($error), ENT_QUOTES), (string) $this->client->getResponse()->getContent(), $error);
            self::assertNull($this->em->getRepository(VpnPanel::class)->findOneBy(['title' => 'v']));
        }
        $this->assertNoTokenInPage();
    }

    public function testHttpToThisServerIsAccepted(): void
    {
        $crawler = $this->client->request('GET', '/admin/panel-setup/new');
        $this->client->submit($crawler->filter('form')->form([
            'panel_connection[title]' => 'local',
            'panel_connection[baseUrl]' => 'http://127.0.0.1:2096/',
            'panel_connection[plainApiToken]' => Fake3xuiPanel::TOKEN,
        ]));

        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    public function testForcingModernOnASellingPanelIsRefusedUntilConfirmed(): void
    {
        $panel = $this->panel();
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('in');
        $this->em->persist($inbound);
        $this->createPlan(inbound: $inbound);
        // A probe status with markup: the refusal flash shows it, escaped (EasyAdmin renders flashes as raw HTML).
        $panel->recordProbe(null, '<b>auth_rejected</b>', new \DateTimeImmutable());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        $form = $crawler->filter('form')->form(['panel_connection[integrationModeValue]' => 'modern']);
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(htmlspecialchars($this->trans('panel_connection.guard.not_confirmed', ['%status%' => '<b>auth_rejected</b>']), ENT_QUOTES), (string) $this->client->getResponse()->getContent());

        // The classic EasyAdmin form applies the same rule.
        $crawler = $this->client->request('GET', '/admin/vpn-panel/'.$panel->getId().'/edit');
        $this->assertResponseIsSuccessfulHere();
        $form = $crawler->filter('form[name="VpnPanel"]')->form();
        $this->choice($form, 'VpnPanel[integrationModeValue]')->select('modern');
        $this->client->submit($form);
        $this->client->followRedirect();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<b>auth_rejected</b>', $html);
        self::assertStringContainsString('&lt;b&gt;auth_rejected&lt;/b&gt;', $html);
        $this->em->clear();
        self::assertSame(SanaeiIntegrationMode::AUTO, $this->em->find(VpnPanel::class, $panel->getId())?->getIntegrationMode());

        // After a successful test the switch is accepted.
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId());
        $this->client->submit($crawler->filter('form[action$="/test"]')->form());
        $crawler = $this->client->request('GET', '/admin/panel-setup/'.$panel->getId().'/edit');
        $this->client->submit($crawler->filter('form')->form(['panel_connection[integrationModeValue]' => 'modern']));
        self::assertTrue($this->client->getResponse()->isRedirect(), $this->bodyExcerpt());
        $this->em->clear();
        self::assertSame(SanaeiIntegrationMode::MODERN, $this->em->find(VpnPanel::class, $panel->getId())?->getIntegrationMode());
    }

    public function testStateChangesNeedTheCsrfToken(): void
    {
        $panel = $this->panel();
        $this->client->request('POST', '/admin/panel-setup/'.$panel->getId().'/test', ['_token' => 'forged']);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame([], $this->fake->requests, 'nothing was sent to the panel');
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function panel(): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('P')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN);
        $this->em->persist($panel);
        $this->em->flush();

        return $panel;
    }

    private function assertNoTokenInPage(): void
    {
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, (string) $this->client->getResponse()->getContent());
    }

    /** (KernelBrowser built by hand: WebTestCase's response assertions are not available.) */
    private function assertResponseIsSuccessfulHere(): void
    {
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->bodyExcerpt());
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        $locale = $this->client->getRequest()->getLocale();

        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $params, 'admin', $locale);
    }

    private function bodyExcerpt(): string
    {
        $content = (string) $this->client->getResponse()->getContent();
        preg_match_all('#<div[^>]*invalid-feedback[^>]*>(.*?)</div>#s', $content, $m);

        return $this->client->getResponse()->getStatusCode().' '.implode(' | ', array_map('strip_tags', $m[1]));
    }
}
