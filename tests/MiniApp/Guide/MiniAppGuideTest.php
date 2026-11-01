<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Entity\BotMessageTemplate;
use App\Entity\ClientApp;
use App\Entity\TrialPlan;
use App\MiniApp\Guide\MiniAppGuide;
use App\Payment\Domain\PaymentGatewayType;
use App\Reseller\Application\ResellerSettings;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Help section's guide (MiniAppGuide, templates/miniapp/view/help.html.twig): a topic exists only while its feature
 * is on, and the guide never mentions a feature that is off; ?topic= opens only a listed topic; the admin's support
 * text is shown as text; the page is the same for every customer and holds no link, token, card number or tracking
 * code. The shop of MiniAppShopFixtures: card to card, Zibal, a custom gateway and NOWPayments, sales open.
 */
final class MiniAppGuideTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const APP_NAME = 'Zeta Tunnel Client';
    private const STORE_URL = 'https://store.example/zeta-tunnel';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
        $this->resetResellerSettings();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM bot_message_template WHERE template_key = 'bot.support'");
        $connection->executeStatement('DELETE FROM client_app WHERE name = ?', [self::APP_NAME]);
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name LIKE 'traffic_addon.%'");
        $connection->executeStatement('DELETE FROM trial_claim');
        $connection->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testTheDefaultShopShowsItsTopicsInOrder(): void
    {
        self::assertSame(['start', 'buy', 'pay', 'connect', 'usage', 'renew', 'troubleshoot', 'faq', 'support'], $this->slugs());
    }

    /**
     * @return iterable<string, array{array<string, string>, string, bool}>
     */
    public static function switches(): iterable
    {
        yield 'sales closed: no buy' => [['sales.new_orders_enabled' => 'false'], 'buy', false];
        yield 'renewals closed: no renew' => [['sales.renewals_enabled' => 'false'], 'renew', false];
        yield 'traffic add-on on: traffic' => [['traffic_addon.enabled' => 'true', 'traffic_addon.price_per_gb' => '5000'], 'traffic', true];
        yield 'traffic add-on without a price: no traffic' => [['traffic_addon.enabled' => 'true', 'traffic_addon.price_per_gb' => '0'], 'traffic', false];
        yield 'traffic add-on off: no traffic' => [['traffic_addon.enabled' => 'false', 'traffic_addon.price_per_gb' => '5000'], 'traffic', false];
        yield 'traffic sales closed: no traffic' => [['traffic_addon.enabled' => 'true', 'traffic_addon.price_per_gb' => '5000', 'sales.add_traffic_enabled' => 'false'], 'traffic', false];
        yield 'wallet on: wallet' => [['wallet.enabled' => 'true'], 'wallet', true];
        yield 'wallet off: no wallet' => [['wallet.enabled' => 'false'], 'wallet', false];
        yield 'claims on: claim' => [['reseller.enabled' => 'true', 'reseller.claims_enabled' => 'true'], 'claim', true];
        yield 'claims without resellers: no claim' => [['reseller.enabled' => 'false', 'reseller.claims_enabled' => 'true'], 'claim', false];
        yield 'resellers without claims: no claim' => [['reseller.enabled' => 'true', 'reseller.claims_enabled' => 'false'], 'claim', false];
    }

    /**
     * @param array<string, string> $settings
     */
    #[DataProvider('switches')]
    public function testATopicFollowsItsSwitch(array $settings, string $slug, bool $visible): void
    {
        foreach ($settings as $key => $value) {
            $this->setting($key, $value);
        }
        $this->resetResellerSettings();

        foreach (['fa', 'en'] as $locale) {
            self::assertSame($visible, in_array($slug, $this->slugs($locale), true), $locale);
        }
    }

    public function testNoPaymentMethodNoPayTopic(): void
    {
        foreach ($this->methods as $method) {
            $method->setIsActive(false);
        }
        $this->em->flush();

        self::assertNotContains('pay', $this->slugs());
    }

    public function testAMethodOnAnInactiveGatewayIsNotListed(): void
    {
        $this->methods[PaymentGatewayType::ZIBAL]->getGateway()->setIsActive(false);
        $this->em->flush();

        $pay = $this->topicText('pay', 'en');
        self::assertStringNotContainsString('زیبال', $pay);
        self::assertStringContainsString('کارت به کارت', $pay);
    }

    public function testThePayTopicListsTheActiveMethodsLabelsAndExplainsOnlyTheirKinds(): void
    {
        $pay = $this->topicText('pay', 'en');
        foreach (['کارت به کارت', 'زیبال', 'درگاه اختصاصی', 'ارز دیجیتال'] as $label) {
            self::assertStringContainsString($label, $pay);
        }
        self::assertStringContainsString('Card to card', $pay);
        self::assertStringContainsString('Online payment', $pay);
        self::assertStringContainsString('Crypto', $pay);

        foreach ([PaymentGatewayType::ZIBAL, PaymentGatewayType::CUSTOM_API, PaymentGatewayType::NOWPAYMENTS] as $type) {
            $this->methods[$type]->setIsActive(false);
        }
        $this->em->flush();
        $pay = $this->topicText('pay', 'en');
        self::assertStringContainsString('Card to card', $pay);
        self::assertStringNotContainsString('Online payment', $pay);
        self::assertStringNotContainsString('Crypto', $pay);
        self::assertStringNotContainsString('online payment', $this->topicText('faq', 'en'));
        self::assertStringNotContainsString('crypto', $this->topicText('faq', 'en'));
    }

    public function testATrialPlanIsMentionedOnlyWhileOneIsActive(): void
    {
        self::assertStringNotContainsString('trial', $this->topicText('buy', 'en'));

        $this->em->persist((new TrialPlan())->setTitle('Trial')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->inbound)->setMaxClaimsPerUser(1));
        $this->em->flush();

        self::assertStringContainsString('free trial account', $this->topicText('buy', 'en'));
    }

    public function testWithEverythingOffTheGuideNeverMentionsAFeature(): void
    {
        foreach (['sales.new_orders_enabled' => 'false', 'sales.renewals_enabled' => 'false', 'sales.add_traffic_enabled' => 'false', 'wallet.enabled' => 'false', 'reseller.enabled' => 'false'] as $key => $value) {
            $this->setting($key, $value);
        }
        foreach ($this->methods as $method) {
            $method->setIsActive(false);
        }
        $this->em->flush();
        $this->resetResellerSettings();

        self::assertSame(['start', 'connect', 'usage', 'troubleshoot', 'faq', 'support'], $this->slugs());
        $text = $this->guideText('en');
        foreach (['wallet', 'Renew', 'renew', 'extra traffic', 'Buy a service', 'seller', 'Card to card', 'card-to-card', 'Online payment', 'online payment', 'crypto', 'Crypto', 'trial'] as $word) {
            self::assertStringNotContainsString($word, $text, 'mentions "'.$word.'"');
        }
        $fa = $this->guideText('fa');
        foreach (['کیف پول', 'تمدید', 'حجم اضافه', 'فروشنده', 'کارت به کارت', 'آنلاین', 'رمزارز', 'اکانت تست'] as $word) {
            self::assertStringNotContainsString($word, $fa, 'mentions "'.$word.'"');
        }
    }

    public function testTheConnectTopicIsGenericEvenWithClientAppsConfigured(): void
    {
        $generic = $this->topicText('connect', 'en');
        self::assertStringContainsString('ask support', $generic);

        $this->em->persist((new ClientApp())->setName(self::APP_NAME)->setPlatforms(['android', 'ios'])->setStoreUrl(self::STORE_URL)->setImportUrlTemplate('zeta://import/{url}'));
        $this->em->flush();

        $withApps = $this->topicText('connect', 'en');
        self::assertSame($generic, $withApps);
        self::assertStringNotContainsString(self::APP_NAME, $this->appGet('/app/view/help?lang=en', $this->session()));
        self::assertStringNotContainsString('store.example', $this->appGet('/app/view/help?lang=en', $this->session()));
    }

    public function testTheHelpViewShowsEveryTopicAsACollapsedCard(): void
    {
        foreach (['fa' => '/app/view/help', 'en' => '/app/view/help?lang=en'] as $locale => $path) {
            $html = $this->appGet($path, $this->session());
            self::assertSame(200, $this->httpStatus());
            $crawler = new Crawler($html);

            self::assertSame($this->slugs($locale), $crawler->filter('details[data-guide-topic]')->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-topic')));
            self::assertCount(0, $crawler->filter('details[open]'));
            self::assertCount(0, $crawler->filter('[data-scroll-to]'));
            self::assertCount(1, $crawler->filter('details[data-guide-topic="support"] [data-support-text]'));
            self::assertCount(1, $crawler->filter('details[data-guide-topic="support"] button[data-close-app]'));
            self::assertCount(0, $crawler->filter('[data-support-card]'));
        }
        self::assertStringContainsString('وقتی وصل نمی‌شود', $this->appGet('/app/view/help', $this->session()));
        self::assertStringContainsString('When it does not connect', $this->appGet('/app/view/help?lang=en', $this->session()));
    }

    public function testTheShellIsRightToLeftInPersianAndLeftToRightInEnglish(): void
    {
        self::assertStringContainsString('dir="rtl"', $this->appGet('/app', null));
        self::assertStringContainsString('dir="ltr"', $this->appGet('/app?lang=en', null));
    }

    public function testTheStaleHelpTextIsGone(): void
    {
        $html = $this->appGet('/app/view/help?lang=en', $this->session()).$this->appGet('/app/view/help', $this->session());

        self::assertStringNotContainsString('done in the bot for now', $html);
        self::assertStringNotContainsString('فعلاً از داخل ربات', $html);
    }

    public function testTopicOpensTheRequestedCard(): void
    {
        $crawler = new Crawler($this->appGet('/app/view/help?lang=en&topic=troubleshoot', $this->session()));

        self::assertSame(['troubleshoot'], $crawler->filter('details[open]')->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-topic')));
        self::assertSame('troubleshoot', $crawler->filter('[data-scroll-to]')->attr('data-guide-topic'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oddTopics(): iterable
    {
        yield 'unknown' => ['topic=nope'];
        yield 'upper case' => ['topic=TROUBLESHOOT'];
        yield 'path' => ['topic=../start'];
        yield 'markup' => ['topic=%3Cscript%3Ealert(1)%3C%2Fscript%3E'];
        yield 'quote' => ['topic=start%22%20onmouseover%3D%22x'];
        yield 'empty' => ['topic='];
        yield 'NUL byte' => ['topic=start%00'];
        yield 'array' => ['topic[]=start'];
        yield 'very long' => ['topic='.str_repeat('a', 5000)];
        yield 'hidden topic (wallet off)' => ['topic=wallet'];
        yield 'trailing newline' => ['topic=start%0A'];
    }

    #[DataProvider('oddTopics')]
    public function testAnOddTopicOpensNothing(string $query): void
    {
        $html = $this->appGet('/app/view/help?lang=en&'.$query, $this->session());

        self::assertSame(200, $this->httpStatus());
        $crawler = new Crawler($html);
        self::assertCount(0, $crawler->filter('details[open]'));
        self::assertGreaterThan(0, $crawler->filter('details[data-guide-topic]')->count());
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('onmouseover', $html);
    }

    public function testTopicLinksInsideTheGuideOpenTheOtherTopic(): void
    {
        $crawler = new Crawler($this->appGet('/app/view/help?lang=en', $this->session()));

        $link = $crawler->filter('details[data-guide-topic="faq"] a[data-nav]')->first();
        self::assertSame('/app/view/help?topic=troubleshoot', $link->attr('href'));
        self::assertNotNull($link->attr('data-replace'));

        // A link to a hidden topic is plain text.
        $this->setting('sales.renewals_enabled', 'false');
        $faq = new Crawler($this->appGet('/app/view/help?lang=en', $this->session()));
        self::assertCount(0, $faq->filter('a[href*="topic=renew"]'));
    }

    public function testTheAdminsSupportTextIsShownAsTextNotAsHtml(): void
    {
        $this->em->persist((new BotMessageTemplate())->setKey('bot.support')->setLocale('fa')->setTitle('support')->setParseMode('html')
            ->setBody("<b>پشتیبانی</b>: @shop_support\n<script>alert(1)</script><img src=x onerror=alert(2)>")->setCategory('help')->setIsActive(true)->setIsSystem(true));
        $this->em->flush();

        $html = $this->appGet('/app/view/help', $this->session());
        $support = (new Crawler($html))->filter('[data-support-text]');

        self::assertSame(0, $support->filter('b, script, img')->count());
        self::assertStringContainsString('<b>پشتیبانی</b>: @shop_support', $support->text());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testTheSupportTextStaysWhenTheSupportTopicIsEmpty(): void
    {
        // An empty topic (G5) is hidden; the support message and the back button then get a card of their own.
        $topics = array_filter(self::getContainer()->get(MiniAppGuide::class)->topics('en'), static fn (array $t): bool => 'support' !== $t['slug']);
        self::assertNotSame([], $topics);

        $html = self::getContainer()->get('twig')->render('miniapp/view/help.html.twig', ['topics' => array_values($topics), 'openTopic' => null, 'supportText' => self::getContainer()->get(MiniAppGuide::class)->plain('Ask us'), 'lang' => 'en']);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('[data-support-card] [data-support-text]'));
        self::assertCount(1, $crawler->filter('[data-support-card] button[data-close-app]'));
    }

    public function testThePageIsTheSameForEveryCustomerAndHoldsNothingSecret(): void
    {
        $service = $this->createActiveService($this->user);
        $service->setSubscriptionUrl('https://sub.panel-shop.example:2096/sub/k3j9x7q2m1zt5w8a');
        $order = $this->createOrder($this->user, $this->plan);
        $order->setTrackingCode('TRK-778899');
        $this->em->flush();

        $mine = $this->appGet('/app/view/help?lang=en', $this->authenticate(self::CUSTOMER));
        $other = $this->appGet('/app/view/help?lang=en', $this->authenticate('8699'));

        self::assertSame($mine, $other, 'nothing of one customer');
        foreach ([self::CARD_NUMBER, str_replace('-', '', self::CARD_NUMBER), 'Shop Owner', 'k3j9x7q2m1zt5w8a', 'panel-shop', 'TRK-778899', 'np-key', 'np-secret', 'test-merchant', 'psp.test', $this->botToken(), '://'] as $secret) {
            self::assertStringNotContainsString($secret, $mine);
        }
    }

    /**
     * @return list<string>
     */
    private function slugs(string $locale = 'fa'): array
    {
        return array_column(self::getContainer()->get(MiniAppGuide::class)->topics($locale), 'slug');
    }

    private function topicText(string $slug, string $locale): string
    {
        foreach (self::getContainer()->get(MiniAppGuide::class)->topics($locale) as $topic) {
            if ($slug === $topic['slug']) {
                return html_entity_decode(strip_tags((string) $topic['body']));
            }
        }
        self::fail($slug.' is not shown');
    }

    private function guideText(string $locale): string
    {
        $text = '';
        foreach (self::getContainer()->get(MiniAppGuide::class)->topics($locale) as $topic) {
            $text .= $topic['title']."\n".html_entity_decode(strip_tags((string) $topic['body']))."\n";
        }

        return $text;
    }

    /**
     * @return array{token: string, csrf: string}
     */
    private function session(): array
    {
        return $this->authenticate(self::CUSTOMER);
    }

    private function resetResellerSettings(): void
    {
        self::getContainer()->get(ResellerSettings::class)->reset();
    }
}
