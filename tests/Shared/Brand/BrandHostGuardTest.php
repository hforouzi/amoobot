<?php

declare(strict_types=1);

namespace App\Tests\Shared\Brand;

use App\Entity\AdminUser;
use App\Shared\UI\BrandHostGuard;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * On the brand host only the Mini App (not its admin section) and the return page exist. The old host keeps everything.
 */
final class BrandHostGuardTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use MiniAppTestTrait;

    private const CUSTOMER = '9301';
    private const ADMIN = '9302';
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    protected function setUp(): void
    {
        // The kernel boots in start(): each test chooses its own environment.
    }

    private function start(?string $brand = self::BRAND_BASE): void
    {
        $this->useBrandDomain($brand);
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    private function assertStatus(int $expected): void
    {
        self::assertSame($expected, $this->client->getResponse()->getStatusCode());
    }

    private function onHost(string $host): void
    {
        $this->client->setServerParameter('HTTP_HOST', $host);
        $this->client->setServerParameter('HTTPS', 'on');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notServed(): iterable
    {
        yield 'home' => ['GET', '/'];
        yield 'EasyAdmin' => ['GET', '/admin'];
        yield 'admin login' => ['GET', '/admin/login'];
        yield 'admin login post' => ['POST', '/admin/login'];
        yield 'front controller path' => ['GET', '/index.php/admin/login'];
        yield 'webhook' => ['POST', '/telegram/webhook/some-secret'];
        yield 'webhook, wrong method' => ['GET', '/telegram/webhook/some-secret'];
        yield 'subscription page' => ['GET', '/s/'.str_repeat('a', 43)];
        yield 'import bridge' => ['GET', '/s/'.str_repeat('a', 43).'/open/1'];
        yield 'download' => ['GET', '/d/1'];
        yield 'zibal callback' => ['GET', '/payment/callback/zibal'];
        yield 'winapay callback' => ['POST', '/payment/callback/plugin/winapay'];
        yield 'custom callback' => ['POST', '/payment/callback/custom-api/1'];
        yield 'nowpayments webhook' => ['POST', '/payment/webhook/nowpayments'];
        yield 'plugin webhook' => ['POST', '/payment/webhook/plugin/swapwallet'];
        yield 'old success page' => ['GET', '/payment/success'];
        yield 'old cancel page' => ['GET', '/payment/cancel'];
        yield 'health' => ['GET', '/healthz'];
        yield 'mini app admin' => ['GET', '/app/admin'];
        yield 'mini app admin page' => ['GET', '/app/admin/apps'];
        yield 'mini app admin trailing slash' => ['GET', '/app/admin/'];
        yield 'mini app admin api' => ['POST', '/app/api/admin/client-apps/check'];
        yield 'mini app admin encoded' => ['GET', '/app/%61dmin/apps'];
        yield 'mini app admin api encoded' => ['POST', '/app/api/%61dmin/client-apps/check'];
        yield 'a short return token' => ['GET', '/payment/return/short'];
        yield 'after the return token' => ['GET', '/payment/return/'.self::TOKEN.'/other'];
        yield 'a logo name that is not one' => ['GET', '/brand/logo/logo.png'];
        yield 'newline after the path' => ['GET', '/payment/return/'.self::TOKEN.'%0A'];
    }

    #[DataProvider('notServed')]
    public function testEverythingElseIs404OnTheBrandHost(string $method, string $path): void
    {
        $this->start();
        $this->onHost('brand.example.org');

        $this->client->request($method, $path);

        $this->assertStatus(404);
        self::assertSame('Not Found', $this->client->getResponse()->getContent());
    }

    public function testAHostInOtherCaseIsTheBrandHost(): void
    {
        $this->start();
        $this->onHost('BRAND.Example.ORG');

        $this->client->request('GET', '/admin/login');

        $this->assertStatus(404);
    }

    public function testTheMiniAppAndTheReturnPageAreServedOnTheBrandHost(): void
    {
        $this->start();
        $this->createUser(self::CUSTOMER);
        $this->onHost('brand.example.org');

        $this->client->request('GET', '/app');
        $this->assertStatus(200);
        self::assertStringContainsString('/miniapp/app.js', (string) $this->client->getResponse()->getContent());

        // Sign-in: initData is checked as ever, and no host enters into it.
        $this->postAuth('not-signed');
        $this->assertStatus(401);
        $session = $this->authenticate(self::CUSTOMER);
        $this->appGet('/app/view/home', $session);
        $this->assertStatus(200);

        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()));
        $token = $payment->ensureReturnToken();
        $this->em->flush();
        $this->client->request('GET', '/payment/return/'.$token);
        $this->assertStatus(200);
        $this->client->request('GET', '/payment/return/'.$token.'/cancel');
        $this->assertStatus(200);
        // Not the guard's 404: the page's own answer for a token nobody holds.
        $this->client->request('GET', '/payment/return/'.self::TOKEN);
        $this->assertStatus(404);
        self::assertNotSame('Not Found', $this->client->getResponse()->getContent());
    }

    public function testTheMiniAppHeadersStayAsTheyAreOnTheBrandHost(): void
    {
        $this->start();
        $this->onHost('brand.example.org');

        $this->client->request('GET', '/app');

        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString('frame-ancestors https://web.telegram.org', $csp);
        self::assertStringContainsString("connect-src 'self'", $csp);
    }

    public function testASignedInAdminGetsNoAdminSectionOnTheBrandHost(): void
    {
        $this->start();
        $this->linkedAdmin('brand@example.test', AdminUser::ROLE_SUPER_ADMIN, self::ADMIN);
        $this->createUser(self::ADMIN);
        $this->onHost('brand.example.org');

        $session = $this->authenticate(self::ADMIN);
        $nav = new Crawler($this->appGet('/app/view/nav', $session));
        $this->assertStatus(200);
        self::assertSame(0, $nav->filter('[href^="/app/admin"], [data-view="admin"], [data-section="admin"]')->count());
        self::assertStringNotContainsString('/app/admin', (string) $this->client->getResponse()->getContent());

        $this->appGet('/app/admin', $session);
        $this->assertStatus(404);
        $this->appGet('/app/admin/apps', $session);
        $this->assertStatus(404);

        $this->onHost('cloud.example.net');
        $session = $this->authenticate(self::ADMIN);
        $body = $this->appGet('/app/view/nav', $session);
        self::assertStringContainsString('/app/admin', $body, 'the old host keeps the admin section');
    }

    public function testTheOperationalHostKeepsEverything(): void
    {
        $this->start();
        $this->onHost('cloud.example.net');

        $this->client->request('GET', '/admin/login');
        $this->assertStatus(200);
        $this->client->request('GET', '/app');
        $this->assertStatus(200);
        $this->client->request('GET', '/healthz');
        self::assertNotSame('Not Found', $this->client->getResponse()->getContent());
        $this->client->request('POST', '/telegram/webhook/wrong-secret');
        self::assertNotSame('Not Found', $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noBrand(): iterable
    {
        yield 'empty' => [null];
        yield 'invalid' => ['http://brand.example.org'];
    }

    /** As if empty: no half-switched state, so the guard does nothing and the host is as any other. */
    #[DataProvider('noBrand')]
    public function testWithoutABrandTheGuardDoesNothing(?string $brand): void
    {
        $this->start($brand);
        $this->onHost('brand.example.org');

        $this->client->request('GET', '/admin/login');

        $this->assertStatus(200);
    }

    public function testEveryRouteFallsOnTheRightSide(): void
    {
        $this->start();
        $served = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            $adminSection = str_starts_with($name, 'miniapp_admin') || str_starts_with((string) $route->getDefault('_miniapp_section'), 'admin');

            if (in_array($name, ['payment_return', 'payment_return_cancel', 'brand_logo'], true)) {
                continue;
            }
            if ($adminSection || !str_starts_with($name, 'miniapp_')) {
                self::assertFalse(BrandHostGuard::isServed($path), sprintf('%s (%s) must not be served on the brand host', $name, $path));
            } else {
                self::assertTrue(BrandHostGuard::isServed($path), sprintf('%s (%s) belongs to the customer Mini App', $name, $path));
                $served[] = $name;
            }
        }

        self::assertContains('miniapp_entry', $served);
        self::assertTrue(BrandHostGuard::isServed('/payment/return/'.self::TOKEN));
        self::assertTrue(BrandHostGuard::isServed('/payment/return/'.self::TOKEN.'/cancel'));
        self::assertTrue(BrandHostGuard::isServed('/brand/logo/'.str_repeat('a', 32).'.png'));
    }
}
