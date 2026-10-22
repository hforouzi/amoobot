<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\AdminUser;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MissingTranslationRecorder;
use Symfony\Component\Yaml\Yaml;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The public subscription page (/s/{token}): what it shows per service state, what it never shows, its headers, rate
 * limit and token rotation, and the bot's "Open subscription page" URL buttons.
 */
final class SubscriptionPageTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '7901';
    private const SUB_URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a';
    private const CONFIG = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.example-shop.com:443?type=tcp&security=none#Germany-01';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        // Every test from its own address: limiter state kept in the cache between runs cannot leak in.
        $this->client->setServerParameter('REMOTE_ADDR', '10.77.'.random_int(0, 255).'.'.random_int(1, 254));
    }

    public function testEveryServiceGetsItsOwnUnguessableTokenThatIsNotThePanelSubId(): void
    {
        $a = $this->service();
        $b = $this->createActiveService($this->createUser());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a->getPublicToken(), '32 random bytes (256 bits), base64url');
        self::assertNotSame($a->getPublicToken(), $b->getPublicToken());
        self::assertStringNotContainsString((string) $a->getSubId(), $a->getPublicToken());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET public_token = ? WHERE id = ?', [$a->getPublicToken(), $b->getId()]);
    }

    public function testActiveServiceShowsStatusExpiryTrafficLinkQrAndAdvancedConfigs(): void
    {
        $service = $this->service();
        $service->setExpiresAt(new \DateTimeImmutable('+20 days 1 hour'))->setTrafficLimitBytes(50 * 1073741824)->setTrafficUsedBytes(10 * 1073741824);
        $this->em->flush();

        $html = $this->open($service);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('lang="fa" dir="rtl"', $html);
        self::assertStringContainsString('Test plan', $html, 'plan name');
        self::assertStringContainsString('data-status="active"', $html);
        self::assertStringContainsString($service->getExpiresAt()?->format('Y-m-d'), $html);
        self::assertStringContainsString('data-days-left="20"', $html);
        self::assertStringContainsString('data-used-percent="20"', $html, 'traffic bar');
        self::assertStringContainsString('40 GB', $html, 'remaining traffic');
        self::assertStringContainsString('data-copy="'.self::SUB_URL.'"', $html, 'copy button carries the subscription URL');
        self::assertMatchesRegularExpression('#<img[^>]+class="qr"[^>]+src="data:image/svg\+xml;base64,#', $html, 'server-side QR');
        self::assertStringContainsString('<details', $html, 'configs collapsed under Advanced');
        self::assertStringContainsString(htmlspecialchars(self::CONFIG, ENT_QUOTES), $html);
    }

    public function testThePageIsPrivateUncachedCookielessAndUsesNoExternalAssets(): void
    {
        $html = $this->open($this->service());
        $headers = $this->client->getResponse()->headers;

        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        self::assertSame([], $headers->getCookies(), 'no cookies');
        self::assertDoesNotMatchRegularExpression('#<(script|link|img)[^>]+(src|href)="(https?:)?//#i', $html, 'no external CDN assets');
    }

    /**
     * Mini App readiness: the Telegram WebApp script is not part of the page; the inline script loads it only when the
     * page was opened as a Mini App (launch parameters in the fragment), then applies Telegram's theme. "Open in
     * browser" uses Telegram.WebApp.openLink there, a visible instruction elsewhere. No cookie, no initData check.
     */
    public function testTheTelegramWebAppIsOnlyLoadedInsideTelegram(): void
    {
        $html = $this->open($this->service());

        self::assertDoesNotMatchRegularExpression('#<script[^>]+src=#i', $html, 'no script file is included up front');
        self::assertStringContainsString("script.src = 'https://telegram.org/js/telegram-web-app.js'", $html);
        self::assertMatchesRegularExpression('#var isMiniApp = /tgWebApp\(Data\|Version\|Platform\)=/\.test\(hash\);#', $html, 'only with Mini App launch parameters');
        self::assertStringContainsString('applyTheme(tg.themeParams)', $html);
        self::assertStringContainsString("params.get('tgWebAppThemeParams')", $html, 'theme also from the launch parameters');
        self::assertStringContainsString('app.openLink(pageUrl()', $html);
        self::assertStringContainsString('data-external-hint hidden', $html, 'instruction outside a Mini App');
        self::assertStringNotContainsString('initData', str_replace('initData !== undefined', '', $html), 'initData is not read');
        self::assertStringContainsString('frame-ancestors https://web.telegram.org', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'), 'Telegram Web can frame it');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testExpiredAndSuspendedServicesShowTheirStateButNoLinks(): void
    {
        foreach ([VpnServiceStatus::EXPIRED, VpnServiceStatus::SUSPENDED] as $status) {
            $service = $this->service()->setStatus($status);
            $this->em->flush();

            $html = $this->open($service);

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $status);
            self::assertStringContainsString('data-status="'.$status.'"', $html);
            self::assertStringNotContainsString(self::SUB_URL, $html, $status.': no link to copy');
            self::assertStringNotContainsString('class="qr"', $html, $status.': no QR');
            $this->purge();
        }
    }

    public function testAnActiveServicePastItsExpiryDateIsShownAsExpired(): void
    {
        $service = $this->service()->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        self::assertStringContainsString('data-status="expired"', $this->open($service));
    }

    /**
     * Persian (RTL) by default, English with ?lang=en; every string of every state is defined in both locales.
     */
    public function testThePageIsTranslatedInPersianAndEnglish(): void
    {
        $recorder = self::getContainer()->get(MissingTranslationRecorder::class);
        $recorder->reset();
        $service = $this->service();
        $this->createActiveService($this->createUser())->setStatus(VpnServiceStatus::SUSPENDED);
        $this->em->flush();

        foreach ([[], ['lang' => 'en']] as $query) {
            foreach ([VpnServiceStatus::ACTIVE, VpnServiceStatus::EXPIRED, VpnServiceStatus::SUSPENDED] as $status) {
                $service->setStatus($status);
                $this->em->flush();
                $this->client->request('GET', '/s/'.$service->getPublicToken(), $query + ['platform' => 'android']);
                self::assertSame(200, $this->client->getResponse()->getStatusCode());
            }
            $this->client->request('GET', '/s/'.str_repeat('z', 43), $query);
        }
        $this->client->request('GET', '/s/'.$service->getPublicToken(), ['lang' => 'en']);
        self::assertStringContainsString('lang="en" dir="ltr"', (string) $this->client->getResponse()->getContent());

        self::assertSame([], $recorder->missing());
        $fa = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir').'/translations/subscription.fa.yaml');
        $en = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir').'/translations/subscription.en.yaml');
        self::assertSame(array_keys($fa), array_keys($en), 'the same keys in both locales');
    }

    public function testUnknownTokensAndDeletedServicesGetTheSameNeutral404(): void
    {
        $service = $this->service()->setStatus(VpnServiceStatus::DELETED);
        $this->em->flush();

        $deleted = $this->open($service);
        $deletedStatus = $this->httpStatus();
        $this->client->request('GET', '/s/'.str_repeat('a', 43));
        $unknown = (string) $this->client->getResponse()->getContent();

        self::assertSame([404, 404], [$deletedStatus, $this->httpStatus()]);

        self::assertSame($unknown, $deleted, 'nothing tells a deleted service from a wrong token');
        self::assertStringNotContainsString(self::SUB_URL, $deleted);
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));

        // Not a token at all (the panel subId, too short): no route.
        $this->client->request('GET', '/s/k3j9x7q2m1zt5w8a');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testThePageNeverShowsThePanelTheCustomerOrAnotherService(): void
    {
        $panel = (new VpnPanel())->setType('test')->setTitle('Secret panel title')->setBaseUrl('https://admin-panel.example-shop.com:2053/s3cr3tpath')->setUsername('panel-admin')->setPlainPassword('panel-password')->setPlainApiToken('panel-api-token');
        $this->em->persist($panel);
        $service = $this->service()->setPanel($panel)->setUsername('tg_7901_order_5')->setRemoteId('remote-99');
        $other = $this->createActiveService($this->createUser('7902'))->setSubscriptionUrl('https://sub.example-shop.com:2096/sub/otherservice00')->setConfigText('vless://other@edge.example-shop.com:443#Other');
        $this->em->flush();

        $html = $this->open($service);

        foreach (['admin-panel.example-shop.com', 's3cr3tpath', 'panel-admin', 'panel-password', 'panel-api-token', 'Secret panel title', 'otherservice00', 'vless://other', '7901', '7902', 'Test User', 'remote-99', $other->getPublicToken()] as $secret) {
            self::assertStringNotContainsString($secret, $html, $secret);
        }
    }

    public function testAPrivateSubscriptionAddressIsWithheld(): void
    {
        $service = $this->service()->setSubscriptionUrl('http://127.0.0.1:2096/sub/k3j9x7q2m1zt5w8a');
        $this->em->flush();

        $html = $this->open($service);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('127.0.0.1', $html);
    }

    public function testRequestsAreRateLimitedPerIp(): void
    {
        $service = $this->service();
        $path = '/s/'.$service->getPublicToken();
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'request '.($i + 1));
        }

        $this->client->request('GET', $path);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        // Wrong tokens count too (no free guessing).
        $this->client->request('GET', '/s/'.str_repeat('b', 43));
        self::assertSame(429, $this->client->getResponse()->getStatusCode());

        $this->client->setServerParameter('REMOTE_ADDR', '10.78.0.'.random_int(1, 254));
        $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'another address is not affected');
    }

    public function testRotationGivesANewLinkAndTheOldOneStopsWorking(): void
    {
        $service = $this->service();
        $old = $service->getPublicToken();
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');

        $this->client->request('POST', '/admin/vpn-service/'.$service->getId().'/rotate-page-link', ['token' => $this->csrf($service)]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $new = (string) $this->em->find(VpnService::class, $service->getId())?->getPublicToken();
        self::assertNotSame($old, $new);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $new);

        $this->client->request('GET', '/s/'.$old);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'old link is dead');
        $this->client->request('GET', '/s/'.$new);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $audit = $this->em->getConnection()->fetchAllAssociative('SELECT action, target_id FROM admin_audit_log');
        self::assertSame([['action' => 'service.rotate_page_link', 'target_id' => (string) $service->getId()]], $audit);
        self::assertStringNotContainsString($old, (string) json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM admin_audit_log')), 'tokens are not logged');
    }

    public function testRotationNeedsServicesManageAPostAndAValidCsrfToken(): void
    {
        $service = $this->service();
        $old = $service->getPublicToken();
        $url = '/admin/vpn-service/'.$service->getId().'/rotate-page-link';

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        // Checked before the CSRF token (Support does not even see the action).
        $this->client->request('POST', $url, ['token' => 'any']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'support cannot rotate');

        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN, 'rot-admin2@shop.test'), 'main');
        $this->client->request('GET', $url);
        self::assertSame(405, $this->client->getResponse()->getStatusCode(), 'GET does not rotate');
        $this->client->request('POST', $url, ['token' => 'forged']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertSame($old, $this->em->find(VpnService::class, $service->getId())?->getPublicToken(), 'unchanged');
    }

    public function testTheAdminServiceDetailShowsThePageLinkAndTheRotateAction(): void
    {
        $service = $this->service();
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');

        $this->client->request('GET', '/admin/vpn-service/'.$service->getId());
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('/s/'.$service->getPublicToken(), $html);
        self::assertStringContainsString('rotate-page-link', $html);
    }

    public function testTheBotServiceViewHasAnOpenSubscriptionPageUrlButton(): void
    {
        $this->publicHost();
        $service = $this->service();

        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertContains('https://shop.example.com/s/'.$service->getPublicToken(), $this->urlButtons());
    }

    public function testThePostPurchaseMessageHasTheButton(): void
    {
        $this->publicHost();
        $user = $this->createUser(self::CUSTOMER);
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);
        $service = $this->service($user)->setOrder($order);
        $this->em->flush();
        $this->http->reset();

        self::getContainer()->get(PaymentCustomerNotifier::class)->confirmed($payment, $service);

        self::assertContains('https://shop.example.com/s/'.$service->getPublicToken(), $this->urlButtons());
    }

    public function testNoUrlButtonWhenTheShopHasNoPublicAddress(): void
    {
        // DEFAULT_URI is http://localhost in tests: Telegram would refuse the whole message.
        $service = $this->service();

        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertSame([], $this->urlButtons());
        self::assertNull(self::getContainer()->get(SubscriptionPageUrl::class)->forTelegram($service));
    }

    private function service(?\App\Entity\User $user = null): VpnService
    {
        $service = $this->createActiveService($user ?? $this->createUser(self::CUSTOMER), $this->createTestInbound())
            ->setSubscriptionUrl(self::SUB_URL)
            ->setSubId('k3j9x7q2m1zt5w8a')
            ->setConfigLinks([self::CONFIG])
            ->setConfigText(self::CONFIG);
        $service->setOrder($this->createOrder($service->getUser(), $this->createPlan()));
        $this->em->flush();
        $this->http->reset();

        return $service;
    }

    private function open(VpnService $service): string
    {
        $this->client->request('GET', '/s/'.$service->getPublicToken());

        return (string) $this->client->getResponse()->getContent();
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function publicHost(): void
    {
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('https')->setHost('shop.example.com');
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

    private function admin(string $role, string $email = 'rot-admin@shop.test'): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    /** The token the detail page's "New subscription page link" form posts (issued in the browser's session). */
    private function csrf(VpnService $service): string
    {
        $this->client->request('GET', '/admin/vpn-service/'.$service->getId());
        self::assertSame(1, preg_match('#rotate-page-link\?token=([^"&]+)#', (string) $this->client->getResponse()->getContent(), $m), 'the detail page has the action');

        return urldecode($m[1]);
    }
}
