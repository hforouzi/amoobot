<?php

declare(strict_types=1);

namespace App\Tests\Shared\Brand;

use App\Branding\BrandSubscriptionPages;
use App\Entity\AdminUser;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Customer subscription pages on the brand domain" (BrandSubscriptionPages): off by default and then exactly as before
 * (OperationalUrlsTest, BrandHostGuardTest); on, every link built for customers to /s/{token} and its import bridges
 * uses the brand base and the brand host serves both pages, while admin screens stay on DEFAULT_URI and the main host
 * keeps serving them. Fail-closed: on without a valid brand domain changes nothing.
 */
final class BrandSubscriptionPagesTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use GuideClientAppFixtures;
    use MiniAppTestTrait;

    private const CUSTOMER = '9401';
    private const ADMIN = '9402';
    private const BRAND_HOST = 'brand.example.org';
    private const MAIN_HOST = 'cloud.example.net';
    private const SUB_URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a';

    protected function setUp(): void
    {
        // The kernel boots in start(): each test chooses its own environment.
    }

    private function start(?string $brand = self::BRAND_BASE, bool $switchedOn = true): void
    {
        $this->useBrandDomain($brand);
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM mini_app_session');
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        if ($switchedOn) {
            $this->em->persist((new Setting())->setKeyName(BrandSubscriptionPages::ENABLED)->setValue('true')->setType('boolean'));
            $this->em->flush();
        }
        self::getContainer()->get(BrandSubscriptionPages::class)->reset();
        $this->startMiniAppClient();
        // Every test from its own address: the page's rate limit kept in the cache between runs cannot leak in.
        $this->client->setServerParameter('REMOTE_ADDR', '10.78.'.random_int(0, 255).'.'.random_int(1, 254));
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    public function testTheBuildersUseTheBrandBaseForCustomersAndDefaultUriForAdmins(): void
    {
        $this->start();
        $service = $this->service();
        $urls = self::getContainer()->get(SubscriptionPageUrl::class);
        $token = $service->getPublicToken();

        self::assertSame(self::BRAND_BASE.'/s/'.$token, $urls->forService($service));
        self::assertSame(self::BRAND_BASE.'/s/'.$token, $urls->forTelegram($service));
        self::assertSame(self::BRAND_BASE.'/s/'.$token.'/open/7', $urls->bridge($token, 7));
        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$token, $urls->forAdmin($service), 'admin screens stay on DEFAULT_URI');
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function failClosed(): iterable
    {
        yield 'switch off' => [self::BRAND_BASE, false];
        yield 'switch on, no brand domain' => [null, true];
        yield 'switch on, invalid brand domain' => ['http://brand.example.org', true];
    }

    #[DataProvider('failClosed')]
    public function testOffOrWithoutABrandDomainEverythingStaysOnDefaultUri(?string $brand, bool $switchedOn): void
    {
        $this->start($brand, $switchedOn);
        $service = $this->service();
        $urls = self::getContainer()->get(SubscriptionPageUrl::class);
        $token = $service->getPublicToken();

        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$token, $urls->forService($service));
        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$token.'/open/7', $urls->bridge($token, 7));
        self::assertSame($switchedOn, self::getContainer()->get(BrandSubscriptionPages::class)->switchedOn());
        self::assertFalse(self::getContainer()->get(BrandSubscriptionPages::class)->active());
    }

    public function testTheBrandHostStill404sTheSubscriptionPagesWhileTheSwitchIsOff(): void
    {
        $this->start(switchedOn: false);
        $token = $this->service()->getPublicToken();
        $this->onHost(self::BRAND_HOST);

        $this->client->request('GET', '/s/'.$token);
        self::assertSame(404, $this->httpStatus());
        self::assertSame('Not Found', $this->client->getResponse()->getContent(), 'the guard, before routing');
    }

    public function testTheBotServiceViewButtonUsesTheBrandBase(): void
    {
        $this->start();
        $service = $this->service();

        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertContains(self::BRAND_BASE.'/s/'.$service->getPublicToken(), $this->urlButtons());
    }

    public function testTheDeliveryNotificationUsesTheBrandBase(): void
    {
        $this->start();
        $user = $this->createUser(self::CUSTOMER);
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);
        $service = $this->service($user)->setOrder($order);
        $this->em->flush();
        $this->http->reset();

        self::getContainer()->get(PaymentCustomerNotifier::class)->confirmed($payment, $service);

        self::assertContains(self::BRAND_BASE.'/s/'.$service->getPublicToken(), $this->urlButtons());
        self::assertStringNotContainsString(self::OPERATIONAL_BASE.'/s/', (string) json_encode($this->http->requests('/sendMessage'), JSON_UNESCAPED_SLASHES));
    }

    public function testTheMiniAppLinksThePageAndTheBridgesOnTheBrandBase(): void
    {
        $this->start();
        $this->addClientApp('Brand import app', ['android'], 1, null, true, 'demo://import/{url}');
        $service = $this->service($this->createUser(self::CUSTOMER));
        $this->onHost(self::BRAND_HOST);
        $session = $this->authenticate(self::CUSTOMER);

        $view = new Crawler($this->appGet('/app/view/services/'.$service->getId(), $session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $view->filter('a[data-open-link][href="'.self::BRAND_BASE.'/s/'.$service->getPublicToken().'"]'));

        $page = new Crawler($this->appGet('/app/view/services/'.$service->getId().'/subscription', $session));
        $bridges = $page->filter('a[data-bridge]')->each(static fn (Crawler $c): string => (string) $c->attr('href'));
        self::assertNotEmpty($bridges);
        foreach ($bridges as $href) {
            self::assertStringStartsWith(self::BRAND_BASE.'/s/'.$service->getPublicToken().'/open/', $href);
        }
    }

    public function testTheMiniAppAdminSectionKeepsDefaultUri(): void
    {
        $this->start();
        $this->linkedAdmin('brand@example.test', AdminUser::ROLE_SUPER_ADMIN, self::ADMIN);
        $this->createUser(self::ADMIN);
        $service = $this->service($this->createUser(self::CUSTOMER));
        $this->onHost(self::BRAND_HOST);

        $html = $this->appGet('/app/admin/services/'.$service->getId(), $this->authenticate(self::ADMIN));

        self::assertSame(200, $this->httpStatus());
        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$service->getPublicToken(), (new Crawler($html))->filter('a[data-page-link]')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        yield 'brand host' => [self::BRAND_HOST];
        yield 'main host' => [self::MAIN_HOST];
    }

    #[DataProvider('hosts')]
    public function testBothPagesAreServedOnBothHostsWithTheirHeaders(string $host): void
    {
        $this->start();
        $app = $this->addClientApp('Brand import app', ['android'], 1, null, true, 'demo://import/{url}');
        $token = $this->service()->getPublicToken();
        $this->onHost($host);

        foreach (['/s/'.$token, '/s/'.$token.'/open/'.$app->getId()] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->httpStatus(), $path);
            $headers = $this->client->getResponse()->headers;
            self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
            self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
            self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
            self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'));
        }
        // An unknown token: the page's own neutral 404, not the guard's.
        $this->client->request('GET', '/s/'.str_repeat('Z', 43));
        self::assertSame(404, $this->httpStatus());
        self::assertNotSame('Not Found', $this->client->getResponse()->getContent());
    }

    public function testTheMainHostServesThePagesWhateverTheSwitch(): void
    {
        $this->start(switchedOn: false);
        $token = $this->service()->getPublicToken();
        $this->onHost(self::MAIN_HOST);

        $this->client->request('GET', '/s/'.$token);

        self::assertSame(200, $this->httpStatus());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stillNotServed(): iterable
    {
        yield 'EasyAdmin' => ['GET', '/admin'];
        yield 'webhook' => ['POST', '/telegram/webhook/some-secret'];
        yield 'download' => ['GET', '/d/1'];
        yield 'health' => ['GET', '/healthz'];
        yield 'zibal callback' => ['GET', '/payment/callback/zibal'];
        yield 'short token' => ['GET', '/s/short'];
        yield 'after the token' => ['GET', '/s/'.str_repeat('a', 43).'/other'];
        yield 'bridge without an id' => ['GET', '/s/'.str_repeat('a', 43).'/open/x'];
        yield 'bridge, trailing path' => ['GET', '/s/'.str_repeat('a', 43).'/open/1/x'];
        yield 'newline after the token' => ['GET', '/s/'.str_repeat('a', 43).'%0A'];
        yield 'the s directory' => ['GET', '/s/'];
    }

    #[DataProvider('stillNotServed')]
    public function testEverythingElseStays404OnTheBrandHost(string $method, string $path): void
    {
        $this->start();
        $this->onHost(self::BRAND_HOST);

        $this->client->request($method, $path);

        self::assertSame(404, $this->httpStatus());
        self::assertSame('Not Found', $this->client->getResponse()->getContent());
    }

    public function testTheTokenIsNeverLogged(): void
    {
        $this->start();
        $app = $this->addClientApp('Brand import app', ['android'], 1, null, true, 'demo://import/{url}');
        $token = $this->service()->getPublicToken();
        $logger = self::getContainer()->get(RecordingLogger::class);
        $records = count($logger->records);
        $file = sys_get_temp_dir().'/amoobot-brand-pages-log-'.bin2hex(random_bytes(4)).'.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $file);
        try {
            foreach ([self::BRAND_HOST, self::MAIN_HOST] as $host) {
                $this->onHost($host);
                $this->client->request('GET', '/s/'.$token);
                $this->client->request('GET', '/s/'.$token.'/open/'.$app->getId());
                $this->client->request('GET', '/s/'.$token.'/open/999999');
            }
            $this->pressButton(self::CUSTOMER, 'service_view:'.$this->em->getRepository(VpnService::class)->findOneBy(['publicToken' => $token])?->getId());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $errorLog = is_file($file) ? (string) file_get_contents($file) : '';
        @unlink($file);
        // Symfony's own info-level "Matched route" record carries the URI; production logs only warnings and above.
        $records = array_filter(array_slice($logger->records, $records), static fn (array $r): bool => 'Matched route "{route}".' !== $r['message']);
        $logged = $errorLog."\n".implode("\n", array_map(static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $records));

        self::assertStringNotContainsString($token, $logged);
    }

    public function testTheSettingIsSavedOnTheBrandingTabAndAudited(): void
    {
        $this->start(switchedOn: false);
        $this->loginWebAdmin();
        $this->onHost(self::MAIN_HOST);

        $crawler = $this->client->request('GET', '/admin/settings?tab=branding');
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="subscription_pages_on_brand"]:not([checked])'));
        self::assertCount(0, $crawler->filter('[data-no-brand-domain]'), 'the brand domain is valid');
        $token = (string) $crawler->filter('form[enctype="multipart/form-data"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/settings?tab=branding', ['brand_name' => '', 'brand_accent_color' => '', 'support_url' => '', 'subscription_pages_on_brand' => 'on', '_token' => $token]);

        self::assertSame('true', $this->em->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [BrandSubscriptionPages::ENABLED]));
        $details = json_decode((string) $this->em->getConnection()->fetchOne('SELECT details FROM admin_audit_log ORDER BY id DESC LIMIT 1'), true);
        self::assertSame('on', $details['subscription_pages_on_brand'] ?? null);

        $crawler = $this->client->request('GET', '/admin/settings?tab=branding');
        self::assertCount(1, $crawler->filter('input[name="subscription_pages_on_brand"][checked]'));
        $token = (string) $crawler->filter('form[enctype="multipart/form-data"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/settings?tab=branding', ['brand_name' => '', 'brand_accent_color' => '', 'support_url' => '', '_token' => $token]);

        self::assertSame('false', $this->em->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [BrandSubscriptionPages::ENABLED]));
        $details = json_decode((string) $this->em->getConnection()->fetchOne('SELECT details FROM admin_audit_log ORDER BY id DESC LIMIT 1'), true);
        self::assertSame('off', $details['subscription_pages_on_brand'] ?? null);
    }

    public function testTheSettingsTabSaysWhenThereIsNoBrandDomain(): void
    {
        $this->start(null, false);
        $this->loginWebAdmin();

        $crawler = $this->client->request('GET', '/admin/settings?tab=branding');

        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $crawler->filter('[data-no-brand-domain]'));
    }

    private function loginWebAdmin(): void
    {
        $admin = (new AdminUser())->setEmail('brand-pages@brand.test')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setPassword('x')->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    private function onHost(string $host): void
    {
        $this->client->setServerParameter('HTTP_HOST', $host);
        $this->client->setServerParameter('HTTPS', 'on');
    }

    private function service(?User $user = null): VpnService
    {
        $service = $this->createActiveService($user ?? $this->createUser(self::CUSTOMER), $this->createTestInbound())
            ->setSubscriptionUrl(self::SUB_URL)
            ->setConfigLinks(['vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#Germany'])
            ->setConfigText('vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#Germany');
        $this->em->flush();

        return $service;
    }

    /**
     * URLs of the inline URL buttons sent since the last reset.
     *
     * @return list<string>
     */
    private function urlButtons(): array
    {
        $urls = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $button) {
                    if (isset($button['url'])) {
                        $urls[] = (string) $button['url'];
                    }
                }
            }
        }

        return $urls;
    }
}
