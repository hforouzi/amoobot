<?php

declare(strict_types=1);

namespace App\Tests\Shared\Brand;

use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Shared\Application\SiteUrls;
use App\Subscription\Application\AppDownloadCatalog;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Requests arriving on the brand host must not leak that host into anything operational: the router would answer with
 * it, so these URLs are built from DEFAULT_URI. Without a brand they stay the router's plain answer.
 */
final class OperationalUrlsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use GuideClientAppFixtures;
    use MiniAppTestTrait;

    private const CUSTOMER = '9101';

    protected function setUp(): void
    {
        $this->useBrandDomain();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    public function testTheBuildersUseDefaultUriWhileTheRequestIsOnTheBrandHost(): void
    {
        $this->requestOnTheBrandHost();
        $urls = self::getContainer()->get(SiteUrls::class);

        self::assertSame(self::BRAND_BASE.'/s/'.str_repeat('a', 43), self::getContainer()->get(RouterInterface::class)->generate('subscription_page', ['token' => str_repeat('a', 43)], RouterInterface::ABSOLUTE_URL), 'the plain router would leak the host');

        self::assertStringStartsWith(self::OPERATIONAL_BASE.'/payment/callback/custom-api/7', $urls->operational('payment_callback_custom_api', ['gatewayId' => 7]));
        self::assertSame(self::OPERATIONAL_BASE.'/payment/webhook/custom-api/7', $urls->operational('payment_webhook_custom_api', ['gatewayId' => 7]));
        self::assertSame(self::BRAND_BASE.'/app', $urls->customer('miniapp_entry'));

        $service = $this->service();
        $subscriptionPages = self::getContainer()->get(SubscriptionPageUrl::class);
        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$service->getPublicToken(), $subscriptionPages->forService($service));
        self::assertSame(self::OPERATIONAL_BASE.'/s/'.$service->getPublicToken(), $subscriptionPages->forTelegram($service));

        $download = $this->addDownload($this->addClientApp('Brand test app', ['android'], 1), 'android', 'https://store.example.com/app');
        $downloads = self::getContainer()->get(AppDownloadCatalog::class);
        self::assertSame(self::OPERATIONAL_BASE.'/d/'.$download->getId(), $downloads->absoluteUrl($download));
        self::assertSame(self::OPERATIONAL_BASE.'/d/'.$download->getId(), $downloads->path($download), 'the Mini App links to a page its host does not serve');
    }

    public function testTheRouterIsLeftAsItWas(): void
    {
        $this->requestOnTheBrandHost();
        $router = self::getContainer()->get(RouterInterface::class);
        $context = $router->getContext();

        self::getContainer()->get(SiteUrls::class)->operational('healthz');

        self::assertSame($context, $router->getContext());
        self::assertSame('brand.example.org', $router->getContext()->getHost());
    }

    public function testTheDeliveryMessageSentFromABrandRequestLinksToTheOperationalHost(): void
    {
        $user = $this->createUser(self::CUSTOMER);
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);
        $service = $this->service($user)->setOrder($order);
        $this->em->flush();
        $this->requestOnTheBrandHost();
        $this->http->reset();

        self::getContainer()->get(PaymentCustomerNotifier::class)->confirmed($payment, $service);

        $sent = json_encode(array_map($this->requestBody(...), $this->http->requests('/sendMessage')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString(self::OPERATIONAL_BASE.'/s/'.$service->getPublicToken(), str_replace('\\', '', (string) $sent));
        self::assertStringNotContainsString('brand.example.org', (string) $sent);
    }

    public function testTheMiniAppOnTheBrandHostLinksImportsAndDownloadsToTheOperationalHost(): void
    {
        $app = $this->addClientApp('Brand import app', ['android'], 1, null, true, 'demo://import/{url}');
        $download = $this->addDownload($app, 'android', 'https://store.example.com/app');
        $service = $this->service($this->createUser(self::CUSTOMER));
        $this->startMiniAppClient();
        $this->client->setServerParameter('HTTP_HOST', 'brand.example.org');
        $this->client->setServerParameter('HTTPS', 'on');

        $session = $this->authenticate(self::CUSTOMER);
        $page = new Crawler($this->appGet('/app/view/services/'.$service->getId().'/subscription', $session));

        $bridges = $page->filter('a[data-bridge]')->each(static fn (Crawler $c): string => (string) $c->attr('href'));
        self::assertNotEmpty($bridges);
        foreach ($bridges as $href) {
            self::assertStringStartsWith(self::OPERATIONAL_BASE.'/s/'.$service->getPublicToken().'/open/', $href);
        }
        $installs = $page->filter('a[href$="/d/'.$download->getId().'"]')->each(static fn (Crawler $c): string => (string) $c->attr('href'));
        self::assertNotEmpty($installs);
        self::assertSame([self::OPERATIONAL_BASE.'/d/'.$download->getId()], array_values(array_unique($installs)));
    }

    private function requestOnTheBrandHost(): void
    {
        $request = Request::create(self::BRAND_BASE.'/app');
        self::getContainer()->get(RequestStack::class)->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->fromRequest($request);
    }

    private function service(?User $user = null): VpnService
    {
        $service = $this->createActiveService($user ?? $this->createUser(self::CUSTOMER), $this->createTestInbound())
            ->setSubscriptionUrl('https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a')
            ->setConfigLinks(['vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#Germany'])
            ->setConfigText('vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#Germany');
        $this->em->flush();

        return $service;
    }
}
