<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Entity\BotMessageTemplate;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The small in-app links into the guide (G-4, templates/miniapp/view/_guide_link.html.twig): each view links to the
 * topic that helps in its situation, only in that situation, and only while that topic is listed in the guide (its
 * feature is on and its text not emptied): never a link to a hidden topic. The href opens that topic's card. The shop
 * of MiniAppShopFixtures: card to card, Zibal, a custom gateway and NOWPayments, sales open, renewals open.
 */
final class MiniAppGuideLinksTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const GB = 1073741824;

    /** @var array{token: string, csrf: string}|null */
    private ?array $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name LIKE 'traffic_addon.%'");
        parent::tearDown();
    }

    public function testWithoutServicesHomeAndServicesLinkToStartAndConnect(): void
    {
        foreach (['/app/view/home', '/app/view/services'] as $view) {
            foreach (['fa' => '', 'en' => '?lang=en'] as $locale => $query) {
                $page = $this->page($view.$query);

                self::assertSame(['start'], $this->linkedTopics($page), $view.' '.$locale);
                self::assertSame('/app/view/help?topic=start', $page->filter('[data-guide-to="start"] a[data-nav]')->attr('href'));
                self::assertSame('/app/view/help?topic=connect', $page->filter('a[data-guide-link]')->attr('href'), 'the install-and-connect button');
            }
        }
        self::assertSame('How it works', trim(str_replace('📖', '', $this->page('/app/view/home?lang=en')->filter('[data-guide-to="start"]')->text())));
        self::assertSame('چطور کار می‌کند؟', trim(str_replace('📖', '', $this->page('/app/view/home')->filter('[data-guide-to="start"]')->text())));
    }

    public function testWithAServiceTheEmptyStateLinksAreGone(): void
    {
        $this->service();

        foreach (['/app/view/home', '/app/view/services'] as $view) {
            $page = $this->page($view);
            self::assertSame([], $this->linkedTopics($page), $view);
            self::assertCount(0, $page->filter('a[data-guide-link]'), $view);
        }
    }

    public function testAnEmptiedTopicHasNoLinkInThatLanguage(): void
    {
        $this->guideRow('guide.start.body', 'en', '');
        $this->guideRow('guide.connect.title', 'en', '');

        $en = $this->page('/app/view/home?lang=en');
        self::assertSame([], $this->linkedTopics($en));
        self::assertCount(0, $en->filter('a[data-guide-link]'));

        $fa = $this->page('/app/view/home');
        self::assertSame(['start'], $this->linkedTopics($fa), 'only in that language');
        self::assertCount(1, $fa->filter('a[data-guide-link]'));
    }

    /**
     * @return iterable<string, array{string, int, array<string, string>, list<string>}>
     */
    public static function services(): iterable
    {
        yield 'active: no link' => [VpnServiceStatus::ACTIVE, 0, [], []];
        yield 'expired: renewing' => [VpnServiceStatus::EXPIRED, 0, [], ['renew']];
        yield 'expired, renewals closed: no link' => [VpnServiceStatus::EXPIRED, 0, ['sales.renewals_enabled' => 'false'], []];
        yield 'suspended: the checklist' => [VpnServiceStatus::SUSPENDED, 0, [], ['troubleshoot']];
        yield 'out of traffic, no add-on: renewing' => [VpnServiceStatus::ACTIVE, 60, [], ['renew']];
        yield 'out of traffic with the add-on: adding traffic' => [VpnServiceStatus::ACTIVE, 60, ['traffic_addon.enabled' => 'true', 'traffic_addon.price_per_gb' => '5000'], ['traffic']];
        yield 'out of traffic, add-on and renewals off: no link' => [VpnServiceStatus::ACTIVE, 60, ['sales.renewals_enabled' => 'false'], []];
    }

    /**
     * @param array<string, string> $settings
     * @param list<string>          $expected
     */
    #[DataProvider('services')]
    public function testAServiceThatIsNotUsableLinksToWhatHelps(string $status, int $usedGb, array $settings, array $expected): void
    {
        foreach ($settings as $key => $value) {
            $this->setting($key, $value);
        }
        $service = $this->service()->setStatus($status)->setTrafficUsedBytes($usedGb * self::GB);
        $this->em->flush();

        foreach (['', '?lang=en'] as $query) {
            $page = $this->page('/app/view/services/'.$service->getId().$query);
            self::assertSame(200, $this->httpStatus());
            self::assertSame($expected, $this->linkedTopics($page), $query);
            $this->assertLinksOpenTheirTopic($page, $query);
        }
    }

    public function testAnEmptiedChecklistHasNoLink(): void
    {
        $this->guideRow('guide.troubleshoot.body', 'fa', '');
        $service = $this->service()->setStatus(VpnServiceStatus::SUSPENDED);
        $this->em->flush();

        self::assertSame([], $this->linkedTopics($this->page('/app/view/services/'.$service->getId())));
        self::assertSame(['troubleshoot'], $this->linkedTopics($this->page('/app/view/services/'.$service->getId().'?lang=en')));
    }

    public function testAfterASuccessfulPurchaseTheLinkIsInstallAndConnect(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, PaymentStatus::CONFIRMED);
        $this->service()->setOrder($order);
        $this->em->flush();

        foreach (['', '?lang=en'] as $query) {
            $page = $this->page('/app/view/payments/'.$payment->getId().$query);
            self::assertSame('paid', $page->filter('[data-payment-state]')->attr('data-payment-state'));
            self::assertSame(['connect'], $this->linkedTopics($page), $query);
            $this->assertLinksOpenTheirTopic($page, $query);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function payments(): iterable
    {
        yield 'pending: paying' => [PaymentStatus::PENDING, ['pay']];
        yield 'receipt in review: paying' => [PaymentStatus::SUBMITTED, ['pay']];
        yield 'rejected: no link' => [PaymentStatus::REJECTED, []];
        yield 'provisioning failed: no link' => [PaymentStatus::PROVISIONING_FAILED, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('payments')]
    public function testAnOpenPaymentLinksToPaying(string $status, array $expected): void
    {
        $payment = $this->payment($this->order(), $status);

        foreach (['', '?lang=en'] as $query) {
            $page = $this->page('/app/view/payments/'.$payment->getId().$query);
            self::assertSame($expected, $this->linkedTopics($page), $query);
            $this->assertLinksOpenTheirTopic($page, $query);
        }
    }

    public function testTheOrderPaymentPageLinksToPayingWhileThatTopicIsShown(): void
    {
        $order = $this->order();

        self::assertSame(['pay'], $this->linkedTopics($this->page('/app/view/orders/'.$order->getId().'/pay')));
        self::assertSame(['pay'], $this->linkedTopics($this->page('/app/view/orders/'.$order->getId().'/pay?lang=en')));

        $this->guideRow('guide.pay.body', 'en', '');
        self::assertSame([], $this->linkedTopics($this->page('/app/view/orders/'.$order->getId().'/pay?lang=en')));
        self::assertSame(['pay'], $this->linkedTopics($this->page('/app/view/orders/'.$order->getId().'/pay')));
    }

    public function testWithoutAnyWayToPayThereIsNoPayLink(): void
    {
        $payment = $this->payment($this->order(), PaymentStatus::PENDING);
        foreach ($this->methods as $method) {
            $method->setIsActive(false);
        }
        $this->em->flush();

        self::assertSame([], $this->linkedTopics($this->page('/app/view/payments/'.$payment->getId())), 'the pay topic is hidden');
    }

    public function testTheWalletLinksToItsTopic(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');

        foreach (['', '?lang=en'] as $query) {
            $page = $this->page('/app/view/wallet'.$query);
            self::assertSame(200, $this->httpStatus());
            self::assertSame(['wallet'], $this->linkedTopics($page), $query);
            $this->assertLinksOpenTheirTopic($page, $query);
        }

        $this->guideRow('guide.wallet.body', 'fa', '');
        self::assertSame([], $this->linkedTopics($this->page('/app/view/wallet')));
    }

    public function testTheAppsPageLinksToInstallAndConnectForTheSameDevice(): void
    {
        $page = $this->page('/app/view/apps?platform=ios');

        self::assertSame(['connect'], $this->linkedTopics($page));
        self::assertSame('/app/view/help?topic=connect&platform=ios', $page->filter('[data-guide-to="connect"] a')->attr('href'));

        $this->guideRow('guide.connect.body', 'fa', '');
        self::assertSame([], $this->linkedTopics($this->page('/app/view/apps?platform=ios')));
    }

    public function testNoViewLinksToATopicThatIsNotListed(): void
    {
        // Everything that can be switched off is off: only the always-shown topics may be linked.
        $this->setting('sales.new_orders_enabled', 'false');
        $this->setting('sales.renewals_enabled', 'false');
        $this->setting(WalletSettings::ENABLED, 'false');
        $service = $this->service()->setStatus(VpnServiceStatus::EXPIRED);
        $payment = $this->payment($this->order(), PaymentStatus::PENDING);
        foreach ($this->methods as $method) {
            $method->setIsActive(false);
        }
        $this->em->flush();

        $listed = array_column(self::getContainer()->get(\App\MiniApp\Guide\MiniAppGuide::class)->topics('fa'), 'slug');
        foreach (['/app/view/home', '/app/view/services/'.$service->getId(), '/app/view/payments/'.$payment->getId(), '/app/view/apps'] as $view) {
            foreach ($this->linkedTopics($this->page($view)) as $slug) {
                self::assertContains($slug, $listed, $view);
            }
        }
        self::assertSame([], $this->linkedTopics($this->page('/app/view/services/'.$service->getId())));
        self::assertSame([], $this->linkedTopics($this->page('/app/view/payments/'.$payment->getId())));
    }

    public function testThePublicSubscriptionPageHasNoGuideLinks(): void
    {
        $service = $this->service()->setStatus(VpnServiceStatus::EXPIRED);
        $this->em->flush();

        $html = $this->appGet('/s/'.$service->getPublicToken(), null);

        self::assertStringNotContainsString('data-guide-to', $html);
        self::assertStringNotContainsString('/app/view/help', $html);
    }

    /**
     * Every link's href opens exactly its topic's card in the Help section, in the same language.
     */
    private function assertLinksOpenTheirTopic(Crawler $page, string $query): void
    {
        foreach ($page->filter('[data-guide-to]') as $node) {
            $slug = (string) (new Crawler($node))->attr('data-guide-to');
            $href = (string) (new Crawler($node))->filter('a[data-nav]')->attr('href');
            self::assertMatchesRegularExpression('#^/app/view/help\?topic='.$slug.'(&platform=[a-z]+)?$#D', $href);
            $help = $this->page($href.('' === $query ? '' : '&lang=en'));
            self::assertCount(1, $help->filter('details[data-guide-topic="'.$slug.'"][open]'), $href);
        }
    }

    /**
     * @return list<string> the topics the page links to, in page order
     */
    private function linkedTopics(Crawler $page): array
    {
        return $page->filter('[data-guide-to]')->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-to'));
    }

    private function page(string $path): Crawler
    {
        $this->session ??= $this->authenticate(self::CUSTOMER);

        return new Crawler($this->appGet($path, $this->session));
    }

    private function service(): VpnService
    {
        $service = $this->createActiveService($this->user, $this->inbound)->setSubscriptionUrl('https://sub.example.test/sub/x1')->setSubId('x1');
        $this->em->flush();
        $this->http->reset();
        $this->answerGateways();

        return $service;
    }

    private function order(): Order
    {
        return $this->createOrder($this->user, $this->plan);
    }

    private function payment(Order $order, string $status): Payment
    {
        return $this->createPayment($order, $this->methods['manual_card']->getGateway(), $status);
    }

    private function guideRow(string $key, string $locale, string $body): void
    {
        $this->em->persist((new BotMessageTemplate())->setKey($key)->setLocale($locale)->setTitle($key)->setBody($body)->setParseMode('guide')
            ->setCategory('guide')->setIsActive(true)->setIsSystem(false));
        $this->em->flush();
    }
}
