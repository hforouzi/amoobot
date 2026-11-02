<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Nothing an admin saves as a guide text can inject HTML or script into the Mini App (G-2, docs/design/help-guide.md,
 * section 3.1), even a row that never went through the form's rules (written straight to the database): the
 * renderer escapes everything, drops invisible characters, cuts very long text and links only to https or a topic.
 * The guide never shows a link, a token or anything of one customer: only the shop-wide {{ bot.* }} variables are
 * filled in.
 */
final class GuideOverrideSafetyTest extends IntegrationTestCase
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
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%'");
        parent::tearDown();
    }

    public function testScriptTagsAndAttributeInjectionAreText(): void
    {
        $this->rawRow('guide.faq.title', '"><img src=x onerror=alert(1)><script>alert(0)</script>');
        $this->rawRow('guide.faq.body', implode("\n", [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(2)>',
            '" onmouseover="alert(3)',
            "' autofocus onfocus='alert(4)",
            '## <svg onload=alert(5)>',
            '- <iframe src="https://evil.example"></iframe>',
            '**<b onclick=alert(6)>bold</b>**',
            '<style>body{display:none}</style>',
        ]));

        $card = $this->card('faq');
        self::assertCount(0, $card->filter('script, img, svg, iframe, style, b'));
        foreach ($card->filter('*') as $element) {
            foreach ($element->attributes ?? [] as $attribute) {
                self::assertStringStartsNotWith('on', $attribute->name, 'no event handler attribute');
                self::assertNotSame('autofocus', $attribute->name);
            }
        }
        self::assertStringContainsString('<script>alert(1)</script>', $card->filter('.guide-body')->text(), 'shown as text');
        self::assertStringContainsString('<img src=x onerror=alert(1)>', $card->filter('summary')->text());
        self::assertSame('<b onclick=alert(6)>bold</b>', $card->filter('.guide-body strong')->text());
    }

    public function testJavascriptAndOtherLinkTargetsAreNotLinks(): void
    {
        $this->rawRow('guide.faq.body', implode("\n", [
            '[a](javascript:alert(1))',
            '[b](javascript:alert)',
            '[c](JaVaScRiPt:alert)',
            '[d](data:text/html;base64,PHNjcmlwdD4=)',
            '[e](vbscript:msgbox)',
            '[f](http://plain.example)',
            '[g](//evil.example)',
            '[h](https://user:pw@evil.example)',
            '[i](https://ok.example/path?q=1&x="y")',
            '[j](#nope)',
            '[k](#pay)',
        ]));

        $card = $this->card('faq');
        $hrefs = $card->filter('.guide-body a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertCount(2, $hrefs, implode(' | ', $hrefs));
        self::assertSame('https://ok.example/path?q=1&x="y"', $hrefs[0], 'the quote is escaped: it stays inside the attribute');
        self::assertStringContainsString('href="https://ok.example/path?q=1&amp;x=&quot;y&quot;"', $this->appGet('/app/view/help?lang=en&topic=faq', $this->authenticate(self::CUSTOMER)), 'as sent');
        self::assertStringContainsString('topic=pay', $hrefs[1]);
        $html = $card->html();
        self::assertStringNotContainsStringIgnoringCase('href="javascript', $html);
        self::assertStringNotContainsStringIgnoringCase('href="data:', $html);
        self::assertStringNotContainsString('href="http://', $html);
        self::assertStringNotContainsString('user:pw@', (string) implode('', $hrefs));
    }

    public function testVeryLongTextIsCutAndFast(): void
    {
        $this->rawRow('guide.faq.title', str_repeat('T', 5000));
        $this->rawRow('guide.faq.body', str_repeat("**a** [x](https://e.example) ::: if wallet\n", 20000).str_repeat('z', 100000));

        $start = microtime(true);
        $card = $this->card('faq');
        self::assertLessThan(5.0, microtime(true) - $start);
        self::assertSame(80, mb_strlen($card->filter('summary')->text()));
        self::assertLessThanOrEqual(8000, mb_strlen($card->filter('.guide-body')->text()) - mb_strlen($card->filter('.guide-support, .muted, button, a.btn')->text('')));
    }

    public function testBidiAndInvisibleCharactersAreRemoved(): void
    {
        $this->rawRow('guide.faq.title', "Pay \u{202E}lmth.exe\u{202C} now");
        $this->rawRow('guide.faq.body', "a\u{202E}b\u{2066}c\u{2069}\u{200B}d\u{FEFF}e\u{E000}f می\u{200C}خواهم\x07\x1B[31m");

        $card = $this->card('faq');
        self::assertSame('Pay lmth.exe now', $card->filter('summary')->text());
        self::assertStringContainsString("abcdef می\u{200C}خواهم[31m", $card->filter('.guide-body')->text(), 'zero-width non-joiner kept');
        self::assertDoesNotMatchRegularExpression('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}\x{E000}\x07\x1B]/u', $card->html());
    }

    public function testNothingOfTheCustomerOrOfTheShopsSecretsIsFilledIn(): void
    {
        $this->rawRow('guide.faq.body', implode("\n", [
            'user: [{{ user.telegramId }}] [{{ user.name }}] [{{ telegramId }}]',
            'service: [{{ service.subscriptionUrl }}] [{{ service.token }}] [{{ order.trackingCode }}]',
            'card: [{{ payment.cardNumber }}] [{{ setting.telegram.bot_token }}]',
            'shop: [{{ bot.brandName }}]',
        ]));

        $html = $this->appGet('/app/view/help?lang=en&topic=faq', $this->authenticate(self::CUSTOMER));
        $text = (new Crawler($html))->filter('details[data-guide-topic="faq"] .guide-body')->text();
        self::assertStringContainsString('user: [] [] []', $text);
        self::assertStringContainsString('service: [] [] []', $text);
        self::assertStringContainsString('card: [] []', $text);
        self::assertStringNotContainsString('[]', str_replace(['user: [] [] []', 'service: [] [] []', 'card: [] []'], '', $text), 'the brand name is filled in');
        self::assertStringNotContainsString(self::CARD_NUMBER, $html);
        self::assertStringNotContainsString(self::CUSTOMER, $text);
    }

    private function card(string $slug): Crawler
    {
        $html = $this->appGet('/app/view/help?lang=en&topic='.$slug, $this->authenticate(self::CUSTOMER));
        $card = (new Crawler($html))->filter('details[data-guide-topic="'.$slug.'"]');
        self::assertCount(1, $card, $slug.' is shown');

        return $card;
    }

    /** A row written straight to the database: the form's rules never saw it. */
    private function rawRow(string $key, string $body): void
    {
        $this->em->getConnection()->insert('bot_message_template', [
            'template_key' => $key, 'locale' => 'en', 'title' => $key, 'body' => $body, 'parse_mode' => 'guide',
            'category' => 'guide', 'is_active' => 1, 'is_system' => 0, 'created_at' => '2026-11-01 10:00:00',
        ]);
    }
}
