<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\MiniApp\Guide\GuideRenderer;
use App\MiniApp\Guide\GuideState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guide's text format (docs/design/help-guide.md, section 3.1): everything is escaped, only the fixed constructs
 * become markup, and no input (raw HTML, attribute injection, bidi tricks, huge or pathological text) gets anything
 * else onto the page.
 */
final class GuideRendererTest extends TestCase
{
    /** The tags and attributes GuideRenderer may write: anything else in its output is a bug. */
    private const ALLOWED_TAGS = ['p', 'br', 'h3', 'ul', 'ol', 'li', 'strong', 'a'];
    private const ALLOWED_ATTRIBUTES = ['href', 'data-nav', 'data-replace', 'data-open-link', 'rel', 'class'];

    private GuideRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new GuideRenderer();
    }

    public function testTheConstructs(): void
    {
        $html = $this->render("Intro line\nsecond line\n\n## Heading\n- one\n- **two**\n\n1. first\n2. second\n\nLast");

        self::assertSame(
            "<p>Intro line<br>second line</p>\n<h3>Heading</h3>\n<ul><li>one</li><li><strong>two</strong></li></ul>\n<ol><li>first</li><li>second</li></ol>\n<p>Last</p>",
            $html,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileInputs(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'img onerror' => ['"><img src=x onerror=alert(1)>', '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;'];
        yield 'html entity stays text' => ['&lt;b&gt;', '&amp;lt;b&amp;gt;'];
        yield 'heading with tag' => ['## <b>x</b>', '<h3>&lt;b&gt;x&lt;/b&gt;</h3>'];
        yield 'bold with tag' => ['**<i>x</i>**', '<strong>&lt;i&gt;x&lt;/i&gt;</strong>'];
        yield 'list item with quote' => ["- it's \"quoted\"", '<li>it&apos;s &quot;quoted&quot;</li>'];
        yield 'javascript link' => ['[click](javascript:alert(1))', '[click](javascript:alert(1))'];
        yield 'data link' => ['[click](data:text/html;base64,PHNjcmlwdD4=)', '<p>click</p>'];
        yield 'http link' => ['[click](http://example.com)', '<p>click</p>'];
        yield 'protocol-relative link' => ['[click](//evil.example)', '<p>click</p>'];
        yield 'link with credentials' => ['[click](https://user:pass@evil.example/)', '<p>click</p>'];
        yield 'quote in a link URL' => ['[x](https://a.example/"onmouseover="alert(1))', '[x](https://a.example/&quot;onmouseover=&quot;alert(1))'];
        yield 'tag in a link label' => ['[<b>x</b>](https://a.example/)', '<a href="https://a.example/" data-open-link rel="noopener noreferrer">&lt;b&gt;x&lt;/b&gt;</a>'];
        yield 'unknown topic link' => ['[x](#nope)', '<p>x</p>'];
        yield 'topic link with odd characters' => ['[x](#start"><b>)', '<p>x</p>'];
        yield 'placeholder inside a line' => ['pay {{ payment_methods }} now', 'pay {{ payment_methods }} now'];
        yield 'other placeholder' => ['{{ bot.token }}', '<p>{{ bot.token }}</p>'];
        yield 'unpaired bold' => ['**open', '<p>**open</p>'];
    }

    #[DataProvider('hostileInputs')]
    public function testHostileInputIsShownAsText(string $input, string $expected): void
    {
        $html = $this->render($input);

        self::assertStringContainsString($expected, $html);
        $this->assertOnlyAllowedMarkup($html);
    }

    public function testExternalHttpsLinksAreOpenedByTelegram(): void
    {
        self::assertSame('<p>See <a href="https://example.com/help?a=1&amp;b=2" data-open-link rel="noopener noreferrer">help</a>.</p>', $this->render('See [help](https://example.com/help?a=1&b=2).'));
    }

    public function testTopicLinksGoOnlyToVisibleTopics(): void
    {
        $url = static fn (string $slug): ?string => 'renew' === $slug ? '/app/view/help?topic=renew' : null;

        self::assertSame('<p><a href="/app/view/help?topic=renew" data-nav data-replace>Renew</a> or Wallet</p>', (string) $this->renderer->render('[Renew](#renew) or [Wallet](#wallet)', $this->state(), $url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invisibleCharacters(): iterable
    {
        yield 'right-to-left override' => ["abc\u{202E}def", 'abcdef'];
        yield 'left-to-right isolate' => ["a\u{2066}b\u{2069}c", 'abc'];
        yield 'zero-width space' => ["a\u{200B}b", 'ab'];
        yield 'byte order mark' => ["\u{FEFF}ab", 'ab'];
        yield 'bell and NUL' => ["a\x07b\x00c", 'abc'];
        yield 'line separator' => ["a\u{2028}b", 'ab'];
        yield 'private use' => ["a\u{E000}b", 'ab'];
        yield 'zero-width non-joiner kept (Persian)' => ["می\u{200C}خواهم", "می\u{200C}خواهم"];
        yield 'zero-width joiner kept (emoji)' => ["👩\u{200D}💻", "👩\u{200D}💻"];
    }

    #[DataProvider('invisibleCharacters')]
    public function testControlAndBidiCharactersAreRemoved(string $input, string $expected): void
    {
        self::assertSame('<p>'.$expected.'</p>', $this->render($input));
        self::assertSame($expected, $this->renderer->title($input));
    }

    public function testInvalidUtf8IsReplacedNotRejected(): void
    {
        $html = $this->render("ok \xC3\x28 ok");

        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
        self::assertStringStartsWith('<p>ok ', $html);
    }

    public function testCarriageReturnsAndTabs(): void
    {
        self::assertSame("<p>a b<br>c</p>\n<p>d</p>", $this->render("a\tb\r\nc\r\rd"));
    }

    public function testVeryLongInputIsCutAndFast(): void
    {
        $started = microtime(true);
        $html = $this->render(str_repeat('x', 1_000_000));

        self::assertSame(GuideRenderer::MAX_CHARS, mb_strlen(strip_tags($html)));
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathologicalInputs(): iterable
    {
        yield 'many bold markers' => [str_repeat('**a', 4000)];
        yield 'many open brackets' => [str_repeat('[', 8000)];
        yield 'many unclosed links' => [str_repeat('[a](', 2000)];
        yield 'many headings' => [str_repeat("## h\n", 2000)];
        yield 'deeply nested conditions' => [str_repeat("::: if wallet\n", 2000).'x'];
        yield 'one huge line of links' => [str_repeat('[a](https://a.example/) ', 400)];
    }

    #[DataProvider('pathologicalInputs')]
    public function testPathologicalInputRendersQuickly(string $input): void
    {
        $started = microtime(true);
        $html = $this->render($input);

        self::assertLessThan(2.0, microtime(true) - $started);
        $this->assertOnlyAllowedMarkup($html);
    }

    public function testConditionalBlocksFollowTheFeatureFlags(): void
    {
        $text = "always\n::: if wallet\nwallet on\n:::\n::: if claims\nclaims on\n:::\n::: if no_such_flag\nnever\n:::\nend";

        self::assertSame('<p>always<br>wallet on<br>end</p>', $this->render($text, $this->state(['wallet' => true])));
        self::assertSame('<p>always<br>end</p>', $this->render($text, $this->state()));
    }

    public function testNestedConditionsNeedEveryFlag(): void
    {
        $text = "::: if wallet\nA\n::: if wallet_payment\nB\n:::\nC\n:::";

        self::assertSame('<p>A<br>B<br>C</p>', $this->render($text, $this->state(['wallet' => true, 'wallet_payment' => true])));
        self::assertSame('<p>A<br>C</p>', $this->render($text, $this->state(['wallet' => true])));
        self::assertSame('', $this->render($text, $this->state(['wallet_payment' => true])));
    }

    public function testAnUnclosedConditionHidesTheRestAndAStrayCloseIsIgnored(): void
    {
        self::assertSame('<p>a</p>', $this->render("a\n::: if wallet\nb", $this->state()));
        self::assertSame('<p>a<br>b</p>', $this->render("a\n:::\nb", $this->state()));
    }

    public function testThePaymentMethodsPlaceholderListsEscapedLabels(): void
    {
        $state = new GuideState(['payment_methods' => true], ['Card <b>to</b> card', 'Online & fast']);

        self::assertSame(
            "<p>Pay with:</p>\n<ul class=\"guide-methods\"><li>Card &lt;b&gt;to&lt;/b&gt; card</li><li>Online &amp; fast</li></ul>",
            $this->render("Pay with:\n{{ payment_methods }}", $state),
        );
        self::assertSame('<p>Pay with:</p>', $this->render("Pay with:\n{{ payment_methods }}", $this->state()));
    }

    public function testPlainTextKeepsTagsAsText(): void
    {
        $html = (string) $this->renderer->plain("<b>Support</b>: @shop_support\n<a href=\"javascript:x\">x</a>\n\n<script>alert(1)</script>");

        self::assertSame("<p>&lt;b&gt;Support&lt;/b&gt;: @shop_support<br>&lt;a href=&quot;javascript:x&quot;&gt;x&lt;/a&gt;</p>\n<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>", $html);
        self::assertSame('', (string) $this->renderer->plain(" \n\n "));
    }

    public function testTitlesArePlainAndShort(): void
    {
        self::assertSame('<b>A</b> B', $this->renderer->title("<b>A</b>\nB"));
        self::assertSame(GuideRenderer::MAX_TITLE_CHARS, mb_strlen($this->renderer->title(str_repeat('t', 500))));
    }

    public function testRandomInputNeverProducesOtherMarkup(): void
    {
        $alphabet = ['<', '>', '"', "'", '&', '[', ']', '(', ')', '#', '*', '-', '1.', '## ', '::: if wallet', ':::', "\n", "\n\n", ' ', 'a', 'ب', 'https://a.example/', 'javascript:', 'script', 'onerror=', '=', '{{ payment_methods }}', "\u{202E}", "\u{200C}", "\x00", '/'];
        mt_srand(20261101);
        for ($i = 0; $i < 500; ++$i) {
            $input = '';
            for ($n = mt_rand(1, 60); $n > 0; --$n) {
                $input .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $this->assertOnlyAllowedMarkup($this->render($input, new GuideState(['wallet' => (bool) mt_rand(0, 1), 'payment_methods' => true], ['<x>'])), $input);
        }
    }

    private function assertOnlyAllowedMarkup(string $html, string $input = ''): void
    {
        preg_match_all('/<\s*\/?\s*([a-zA-Z0-9]+)([^>]*)>/', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $tag, $attributes]) {
            self::assertContains(strtolower($tag), self::ALLOWED_TAGS, 'tag in: '.$input);
            preg_match_all('/([a-zA-Z-]+)(?:="[^"]*")?/', $attributes, $names);
            foreach ($names[1] as $name) {
                self::assertContains(strtolower($name), self::ALLOWED_ATTRIBUTES, 'attribute in: '.$input);
            }
            if (preg_match('/href="([^"]*)"/', $attributes, $href)) {
                self::assertMatchesRegularExpression('#^(https://|/app/view/help\?topic=)#', html_entity_decode($href[1]), 'href in: '.$input);
            }
        }
        self::assertStringNotContainsStringIgnoringCase('<script', $html);
        self::assertDoesNotMatchRegularExpression('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x00]/u', $html, 'invisible characters in: '.$input);
    }

    /**
     * @param array<string, bool> $flags
     */
    private function state(array $flags = []): GuideState
    {
        return new GuideState($flags);
    }

    private function render(string $text, ?GuideState $state = null): string
    {
        return (string) $this->renderer->render($text, $state ?? $this->state(), static fn (string $slug): ?string => null);
    }
}
