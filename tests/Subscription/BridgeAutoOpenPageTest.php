<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\ClientApp;
use App\Entity\VpnService;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The import bridge (/s/{token}/open/{appId}) opens the app by itself once, so "add to app" in the Mini App is one tap;
 * the big button and the copy fallback stay.
 */
final class BridgeAutoOpenPageTest extends IntegrationTestCase
{
    use GuideClientAppFixtures;

    private const CUSTOMER = '9501';
    private const SUB_URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private KernelBrowser $browser;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = new KernelBrowser(self::$kernel);
        $this->browser->disableReboot();
        $this->browser->setServerParameter('REMOTE_ADDR', '10.92.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->service = $this->createActiveService($this->createUser(self::CUSTOMER), $this->createTestInbound())->setSubscriptionUrl(self::SUB_URL);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        parent::tearDown();
    }

    public function testOnAndroidWithAPlayStorePackageItOpensAnIntentWithTheFallbackToThisPage(): void
    {
        $app = $this->app('https://play.google.com/store/apps/details?id=com.v2ray.ang');

        $page = $this->bridge($app, self::ANDROID);

        $target = $this->autoOpen($page);
        self::assertNotNull($target);
        self::assertStringStartsWith('intent://install-sub?url='.rawurlencode(self::SUB_URL), $target);
        self::assertStringContainsString('#Intent;scheme=v2rayng;package=com.v2ray.ang;S.browser_fallback_url=', $target);
        self::assertStringEndsWith(';end', $target);
        $fallback = rawurldecode((string) preg_replace('/^.*;S\.browser_fallback_url=([^;]*);end$/', '$1', $target));
        self::assertSame('http://localhost/s/'.$this->service->getPublicToken().'/open/'.$app->getId().'?noauto=1', $fallback, 'this page, never trying again');
        $this->assertTheFallbacksStay($page);
        self::assertCount(1, $page->filter('script[data-auto-open-script]'));
        self::assertStringContainsString('window.location.href = target', $page->filter('script[data-auto-open-script]')->text());
    }

    public function testWithoutAPackageItOpensThePlainImportLink(): void
    {
        $app = $this->app('https://github.com/2dust/v2rayNG/releases');

        $page = $this->bridge($app, self::ANDROID);

        self::assertSame($page->filter('a.btn[data-deeplink]')->attr('href'), $this->autoOpen($page));
        self::assertStringStartsWith('v2rayng://install-sub?url=', (string) $this->autoOpen($page));
        $this->assertTheFallbacksStay($page);
    }

    public function testOnIosItOpensThePlainImportLink(): void
    {
        $app = $this->app('https://play.google.com/store/apps/details?id=com.v2ray.ang');

        $page = $this->bridge($app, self::IPHONE);

        self::assertStringStartsWith('v2rayng://install-sub?url=', (string) $this->autoOpen($page));
        $this->assertTheFallbacksStay($page);
    }

    public function testTheEnglishFallbackKeepsTheLanguage(): void
    {
        $app = $this->app('https://play.google.com/store/apps/details?id=com.v2ray.ang');

        $page = $this->bridge($app, self::ANDROID, '?lang=en');

        self::assertStringContainsString(rawurlencode('?noauto=1&lang=en'), (string) $this->autoOpen($page));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noAutoOpen(): iterable
    {
        yield 'noauto=1' => [self::ANDROID, '?noauto=1'];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ''];
        yield 'Telegram link preview' => ['TelegramBot (like TwitterBot)', ''];
        yield 'no user agent' => ['', ''];
    }

    /** Crawlers, link previews and ?noauto=1 get the page with its button, and no script. */
    #[DataProvider('noAutoOpen')]
    public function testNoAutomaticTryForCrawlersOrNoauto(string $userAgent, string $query): void
    {
        $app = $this->app('https://play.google.com/store/apps/details?id=com.v2ray.ang');

        $page = $this->bridge($app, $userAgent, $query);

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertNull($this->autoOpen($page));
        self::assertCount(0, $page->filter('script[data-auto-open-script]'));
        self::assertStringNotContainsString('intent://', $page->html());
        $this->assertTheFallbacksStay($page);
    }

    public function testAHeadRequestIsAnsweredWithoutAnAutomaticTry(): void
    {
        $app = $this->app('https://play.google.com/store/apps/details?id=com.v2ray.ang');
        $this->browser->setServerParameter('HTTP_USER_AGENT', self::ANDROID);

        $this->browser->request('HEAD', '/s/'.$this->service->getPublicToken().'/open/'.$app->getId());

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('data-auto-open', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheCspIsUnchanged(): void
    {
        $app = $this->app(null);

        $this->bridge($app, self::ANDROID);

        self::assertSame("default-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline' https://telegram.org; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors https://web.telegram.org 'self'", $this->browser->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function assertTheFallbacksStay(Crawler $page): void
    {
        self::assertCount(1, $page->filter('a.btn[data-deeplink]'), 'the big button');
        self::assertStringStartsWith('v2rayng://', (string) $page->filter('a.btn[data-deeplink]')->attr('href'));
        self::assertCount(1, $page->filter('[data-fallback] button[data-copy="'.self::SUB_URL.'"]'), 'the copy-link fallback');
    }

    private function app(?string $storeUrl): ClientApp
    {
        return $this->addClientApp('v2rayNG test', ['android', 'ios'], 1, $storeUrl, true, 'v2rayng://install-sub?url={url_encoded}#{name}');
    }

    private function bridge(ClientApp $app, string $userAgent, string $query = ''): Crawler
    {
        $this->browser->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $this->browser->request('GET', '/s/'.$this->service->getPublicToken().'/open/'.$app->getId().$query);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());

        return new Crawler((string) $this->browser->getResponse()->getContent());
    }

    private function autoOpen(Crawler $page): ?string
    {
        $card = $page->filter('[data-auto-open]');

        return 0 === $card->count() ? null : $card->attr('data-auto-open');
    }
}
