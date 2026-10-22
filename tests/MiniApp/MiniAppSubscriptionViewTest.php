<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\ClientApp;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The subscription page inside the Mini App (/app/view/services/{id}/subscription): the owner's own subscription
 * link, QR code, configs and one-tap imports, the only /app response that may carry them. Imports go through an
 * https bridge page (/s/{token}/open/{app}) opened in the external browser, because Telegram's WebView blocks custom
 * schemes. Panel addresses and tokens appear nowhere, and nobody else's subscription is reachable.
 */
final class MiniAppSubscriptionViewTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;

    private const CUSTOMER = '8701';
    private const SUB_URL = 'https://sub.panel-shop.com:2096/sub/s7k2q9x4m1vb3n8c';
    private const SUB_ID = 's7k2q9x4m1vb3n8c';
    private const CONFIG = 'vless://4f0c2d1e-9a8b-4c7d-8e6f-5a4b3c2d1e0f@edge.panel-shop.com:443?type=tcp&security=none#Germany';
    private const PANEL_BASE = 'https://admin.panel-shop.com:2053/secretpath/';
    private const PANEL_TOKEN = 'panel-api-token-SECRET1';

    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->service = $this->service($this->createUser(self::CUSTOMER));
        $this->startMiniAppClient();
    }

    public function testTheOwnerSeesTheirLinkQrConfigsAndOneTapImports(): void
    {
        $html = $this->appGet($this->url($this->service), $this->authenticate(self::CUSTOMER));
        $page = new Crawler($html);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('services', $page->filter('.view-root')->attr('data-view'));
        self::assertSame('active', $page->filter('[data-status]')->attr('data-status'), 'the shared status component');
        self::assertSame(self::SUB_URL, $page->filter('button[data-copy]')->first()->attr('data-copy'), 'copy the subscription link');
        self::assertMatchesRegularExpression('#<img[^>]+class="qr"[^>]+src="data:image/svg\+xml;base64,#', $html, 'server-side QR');
        self::assertStringContainsString(htmlspecialchars(self::CONFIG, ENT_QUOTES), $html, 'configs under Advanced');
        self::assertCount(1, $page->filter('details'));

        $bridges = $page->filter('a[data-open-link][data-bridge]')->each(static fn (Crawler $c): string => (string) $c->attr('href'));
        self::assertNotEmpty($bridges);
        foreach ($bridges as $href) {
            self::assertMatchesRegularExpression('#^http://localhost/s/'.preg_quote($this->service->getPublicToken(), '#').'/open/\d+$#', $href, 'an https(s) bridge page, never a custom scheme in the WebView');
        }
        self::assertCount(0, $page->filter('a[href^="hiddify:"], a[href^="v2rayng:"], a[href^="sn:"]'), 'no custom scheme link inside Telegram');
        $hiddify = $this->app('Hiddify');
        self::assertContains('http://localhost/s/'.$this->service->getPublicToken().'/open/'.$hiddify->getId(), $bridges);
    }

    public function testTheSubscriptionViewIsTheOnlyAppResponseWithTheLinkAndNoneHasPanelSecrets(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $id = (int) $this->service->getId();
        $views = ['/app/view/home', '/app/view/services', '/app/view/services/'.$id, '/app/view/shop', '/app/view/orders', '/app/view/apps', '/app/view/help', '/app/view/nav', '/app/api/me'];

        foreach ($views as $path) {
            $content = $this->appGet($path, $session);
            self::assertSame(200, $this->httpStatus(), $path);
            foreach ([self::SUB_URL, self::SUB_ID, self::CONFIG] as $secret) {
                self::assertStringNotContainsString($secret, $content, $path.' shows the subscription');
            }
            $this->assertNoPanelSecrets($content, $path, $session['token']);
        }

        $content = $this->appGet($this->url($this->service), $session);
        self::assertStringContainsString(self::SUB_URL, $content);
        $this->assertNoPanelSecrets($content, 'subscription view', $session['token']);
    }

    public function testNobodyElsesSubscriptionIsReachable(): void
    {
        $theirs = $this->service($this->createUser('8702'));
        $deleted = $this->service($this->service->getUser())->setStatus(VpnServiceStatus::DELETED);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);

        foreach ([$theirs, $deleted] as $service) {
            $content = $this->appGet($this->url($service), $session);
            self::assertSame(404, $this->httpStatus());
            self::assertStringNotContainsString(self::SUB_URL, $content);
        }
        $this->appGet('/app/view/services/'.$this->service->getId().'/subscription', null);
        self::assertSame(401, $this->httpStatus(), 'the Mini App session, not the public link');
    }

    public function testTheBridgePageTriggersTheAppAndOffersTheLink(): void
    {
        $browser = $this->externalBrowser();
        $hiddify = $this->app('Hiddify');

        $browser->request('GET', '/s/'.$this->service->getPublicToken().'/open/'.$hiddify->getId());
        $html = (string) $browser->getResponse()->getContent();
        $page = new Crawler($html);

        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $open = $page->filter('a.btn[data-deeplink]');
        self::assertCount(1, $open, 'one big "Open in Hiddify" button');
        self::assertStringStartsWith('hiddify://install-config?url='.rawurlencode(self::SUB_URL), (string) $open->attr('href'));
        self::assertStringContainsString('Hiddify', $open->text());
        self::assertSame(self::SUB_URL, $page->filter('button[data-copy]')->attr('data-copy'), 'copy-link fallback');
        $headers = $browser->getResponse()->headers;
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame([], $headers->getCookies());
        $this->assertNoPanelSecrets($html, 'bridge', null);
    }

    public function testTheBridgeOnlyOpensWorkingLinksOfActiveImportingApps(): void
    {
        $browser = $this->externalBrowser();
        $token = $this->service->getPublicToken();
        $v2rayN = $this->app('v2rayN');
        $inactive = $this->app('Happ');

        $browser->request('GET', '/s/'.$token.'/open/'.$v2rayN->getId());
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'no URL scheme: nothing to open');
        $browser->request('GET', '/s/'.$token.'/open/'.$inactive->getId());
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'inactive app');
        $browser->request('GET', '/s/'.str_repeat('A', 43).'/open/'.$this->app('Hiddify')->getId());
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'unknown token');

        // Straight in the database: the requests reset the entity manager (the entity is detached).
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET status = ? WHERE id = ?', [VpnServiceStatus::EXPIRED, $this->service->getId()]);
        $browser->request('GET', '/s/'.$token.'/open/'.$this->app('Hiddify')->getId());
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'an expired service has no working link to import');
    }

    public function testTheSubscriptionViewIsLinkedFromTheServicePage(): void
    {
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $this->authenticate(self::CUSTOMER)));

        self::assertSame($this->url($this->service), $page->filter('a[data-nav][data-subscription-view]')->attr('href'));
    }

    private function assertNoPanelSecrets(string $content, string $where, ?string $sessionToken): void
    {
        foreach (array_filter(['admin.panel-shop.com', 'secretpath', self::PANEL_TOKEN, $this->botToken(), $sessionToken]) as $secret) {
            self::assertStringNotContainsString((string) $secret, $content, $where.' leaks '.($secret === $sessionToken ? 'the session token' : $secret));
        }
    }

    private function service(\App\Entity\User $user): VpnService
    {
        $inbound = $this->createTestInbound();
        $inbound->getPanel()->setBaseUrl(self::PANEL_BASE)->setPlainApiToken(self::PANEL_TOKEN);
        $service = $this->createActiveService($user, $inbound)
            ->setSubscriptionUrl(self::SUB_URL)
            ->setSubId(self::SUB_ID)
            ->setConfigLinks([self::CONFIG])
            ->setConfigText(self::CONFIG);
        $service->setOrder($this->createOrder($user, $this->createPlan()));
        $this->em->flush();
        $this->http->reset();

        return $service;
    }

    private function url(VpnService $service): string
    {
        return '/app/view/services/'.$service->getId().'/subscription';
    }

    private function app(string $name): ClientApp
    {
        return $this->em->getRepository(ClientApp::class)->findOneBy(['name' => $name]) ?? throw new \LogicException('seeded client app '.$name.' missing');
    }

    private function externalBrowser(): KernelBrowser
    {
        $browser = new KernelBrowser(self::$kernel);
        $browser->disableReboot();
        $browser->setServerParameter('REMOTE_ADDR', '10.91.'.random_int(0, 255).'.'.random_int(1, 254));

        return $browser;
    }
}
