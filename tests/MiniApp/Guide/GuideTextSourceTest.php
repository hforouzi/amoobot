<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Entity\BotMessageTemplate;
use App\Entity\Setting;
use App\MiniApp\Guide\GuideRenderer;
use App\MiniApp\Guide\GuideState;
use App\MiniApp\Guide\GuideStateProvider;
use App\MiniApp\Guide\GuideTopic;
use App\MiniApp\Guide\MiniAppGuide;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the guide's texts come from (G-2): BotTextResolver::guideText(), i.e. the admin's Bot Content row for exactly
 * that language, else the shipped default (translations/bot.*.yaml). Without rows the guide is exactly what G-1
 * rendered straight from the translator; an empty body hides its topic; an edit never makes a switched-off topic
 * appear; a Persian edit never shows on the English guide.
 */
final class GuideTextSourceTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name = 'bot.brand_name'");
        parent::tearDown();
    }

    /**
     * Regression: with no Bot Content row the topics (titles and rendered bodies) are byte for byte what the G-1 code
     * produced from the translator, in both languages, with features on and off.
     */
    public function testWithoutRowsTheGuideIsWhatTheTranslationsGaveBefore(): void
    {
        $states = [
            'shop as configured' => self::getContainer()->get(GuideStateProvider::class)->current(),
            'everything on' => new GuideState(array_fill_keys(GuideState::FLAGS, true), ['Card <b>to</b> card', 'Zibal']),
            'everything off' => new GuideState([]),
        ];
        foreach (['fa', 'en'] as $locale) {
            foreach ($states as $name => $state) {
                self::assertSame($this->beforeG2($locale, $state), $this->now($locale, $state), $locale.', '.$name);
            }
        }
    }

    public function testWithoutRowsTheHelpPageIsUnchanged(): void
    {
        $guide = self::getContainer()->get(MiniAppGuide::class);
        foreach (['fa' => '/app/view/help', 'en' => '/app/view/help?lang=en'] as $locale => $path) {
            $html = $this->appGet($path, $this->authenticate(self::CUSTOMER));
            $state = self::getContainer()->get(GuideStateProvider::class)->current();
            foreach ($this->beforeG2($locale, $state) as $topic) {
                self::assertStringContainsString('<bdi>'.htmlspecialchars($topic['title'], ENT_QUOTES | ENT_HTML5).'</bdi>', $html, $locale);
                self::assertStringContainsString($topic['body'], $html, $locale.' '.$topic['slug']);
            }
            self::assertSame(array_column($this->beforeG2($locale, $state), 'slug'), (new Crawler($html))->filter('details[data-guide-topic]')->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-topic')));
            self::assertCount(count($guide->topics($locale)), (new Crawler($html))->filter('details[data-guide-topic]'));
        }
    }

    public function testAnEditShowsInItsLanguageOnly(): void
    {
        $this->guideRow('guide.connect.body', 'fa', "## نصب\nمتن تازهٔ مدیر");
        $this->guideRow('guide.connect.title', 'fa', 'اتصال (ویرایش‌شده)');

        self::assertSame('اتصال (ویرایش‌شده)', $this->topic('connect', 'fa')['title']);
        self::assertSame("<h3>نصب</h3>\n<p>متن تازهٔ مدیر</p>", (string) $this->topic('connect', 'fa')['body']);
        $en = $this->topic('connect', 'en');
        self::assertSame('Install and connect', $en['title'], 'no Persian edit on the English guide');
        self::assertStringContainsString('Install Happ', (string) $en['body']);

        $this->guideRow('guide.connect.body', 'en', 'English edit');
        self::assertSame('<p>English edit</p>', (string) $this->topic('connect', 'en')['body']);
    }

    public function testAnInactiveRowIsIgnored(): void
    {
        $this->guideRow('guide.start.body', 'en', 'Switched off', false);

        self::assertStringContainsString('This shop sells VPN services.', (string) $this->topic('start', 'en')['body']);
    }

    public function testAnEmptyBodyHidesTheTopicAndResettingBringsItBack(): void
    {
        $row = $this->guideRow('guide.faq.body', 'en', '');
        self::assertNotContains('faq', $this->slugs('en'));
        self::assertContains('faq', $this->slugs('fa'), 'only in that language');
        self::assertCount(0, (new Crawler($this->appGet('/app/view/help?lang=en', $this->authenticate(self::CUSTOMER))))->filter('details[data-guide-topic="faq"]'));

        $this->em->getConnection()->delete('bot_message_template', ['id' => $row->getId()]);
        self::assertContains('faq', $this->slugs('en'));
    }

    public function testAnEditNeverMakesASwitchedOffTopicAppear(): void
    {
        $this->setting('sales.new_orders_enabled', 'false');
        $this->setting('wallet.enabled', 'false');
        $this->guideRow('guide.buy.body', 'en', 'Buy now!');
        $this->guideRow('guide.wallet.body', 'en', 'Wallet!');

        self::assertNotContains('buy', $this->slugs('en'));
        self::assertNotContains('wallet', $this->slugs('en'));
        $html = $this->appGet('/app/view/help?lang=en', $this->authenticate(self::CUSTOMER));
        self::assertStringNotContainsString('Buy now!', $html);
        self::assertStringNotContainsString('Wallet!', $html);
    }

    public function testPlaceholdersInAnEdit(): void
    {
        $this->setting('bot.brand_name', 'Amoo <VPN>');
        $this->guideRow('guide.pay.body', 'en', "Welcome to {{ bot.brandName }}.\n{{payment_methods}}\nUnknown: [{{ user.name }}]");

        $body = (string) $this->topic('pay', 'en')['body'];
        self::assertStringContainsString('Welcome to Amoo &lt;VPN&gt;.', $body, 'the shop-wide bot variable, escaped');
        self::assertStringContainsString('<ul class="guide-methods">', $body, 'the payment methods placeholder is kept for the renderer');
        self::assertStringContainsString('Unknown: []', $body, 'anything else is empty, as in bot messages');
    }

    public function testTheHelpViewShowsAnOverriddenTextEscaped(): void
    {
        $this->guideRow('guide.support.body', 'en', "**Write to us** any time.\n<script>alert(1)</script>\n[x](javascript:alert(2))");
        $this->guideRow('guide.support.title', 'en', 'Help <b>desk</b>');

        $html = $this->appGet('/app/view/help?lang=en&topic=support', $this->authenticate(self::CUSTOMER));
        $card = (new Crawler($html))->filter('details[data-guide-topic="support"]');

        self::assertSame('Help <b>desk</b>', $card->filter('summary')->text());
        self::assertSame('Write to us', $card->filter('.guide-body strong')->text());
        self::assertNotNull($card->attr('open'));
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertCount(0, $card->filter('.guide-body b, .guide-body script'));
    }

    /**
     * What G-1 did: the translator, then GuideRenderer.
     *
     * @return list<array{slug: string, title: string, body: string}>
     */
    private function beforeG2(string $locale, GuideState $state): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $renderer = self::getContainer()->get(GuideRenderer::class);
        $text = static function (string $key) use ($translator, $locale): string {
            $value = $translator->trans($key, [], 'bot', $locale);

            return $value === $key ? '' : $value;
        };
        $candidates = [];
        foreach (GuideTopic::cases() as $topic) {
            if ($topic->isVisible($state)) {
                $title = $renderer->title($text($topic->titleKey()));
                $body = $text($topic->bodyKey());
                if ('' !== $title && '' !== trim($body)) {
                    $candidates[$topic->value] = [$title, $body];
                }
            }
        }
        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        $topicUrl = static fn (string $slug): ?string => isset($candidates[$slug]) ? $urls->generate('miniapp_help', ['topic' => $slug]) : null;
        $topics = [];
        foreach ($candidates as $slug => [$title, $body]) {
            $html = (string) $renderer->render($body, $state, $topicUrl);
            if ('' !== $html) {
                $topics[] = ['slug' => $slug, 'title' => $title, 'body' => $html];
            }
        }

        return $topics;
    }

    /**
     * @return list<array{slug: string, title: string, body: string}>
     */
    private function now(string $locale, GuideState $state): array
    {
        return array_map(static fn (array $t): array => ['slug' => $t['slug'], 'title' => $t['title'], 'body' => (string) $t['body']], self::getContainer()->get(MiniAppGuide::class)->topics($locale, $state));
    }

    /**
     * @return array{slug: string, title: string, body: \Twig\Markup}
     */
    private function topic(string $slug, string $locale): array
    {
        foreach (self::getContainer()->get(MiniAppGuide::class)->topics($locale) as $topic) {
            if ($slug === $topic['slug']) {
                return $topic;
            }
        }
        self::fail($slug.' is not shown in '.$locale);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $locale): array
    {
        return array_column(self::getContainer()->get(MiniAppGuide::class)->topics($locale), 'slug');
    }

    private function guideRow(string $key, string $locale, string $body, bool $active = true): BotMessageTemplate
    {
        $row = (new BotMessageTemplate())->setKey($key)->setLocale($locale)->setTitle($key)->setBody($body)->setParseMode('guide')
            ->setCategory('guide')->setIsActive($active)->setIsSystem(false);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
