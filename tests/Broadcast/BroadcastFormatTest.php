<?php

declare(strict_types=1);

namespace App\Tests\Broadcast;

use App\Broadcast\BroadcastFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A broadcast's text checked against Telegram's MarkdownV2 and HTML rules before anything is sent (no call to
 * Telegram), and the "escape automatically" helper that turns plain text into valid MarkdownV2.
 */
final class BroadcastFormatTest extends TestCase
{
    public function testPlainTextIsNeverAProblem(): void
    {
        self::assertNull(BroadcastFormat::problem('Prices from 1.5 to 2.0! (limited) <b> & #tags_*', null));
        self::assertNull(BroadcastFormat::problem('anything', ''));
    }

    public function testAnUnescapedDotInMarkdownV2IsRefusedWithItsLine(): void
    {
        $problem = BroadcastFormat::problem("سلام\nنسخه 2.5 آماده است", 'MarkdownV2');

        self::assertNotNull($problem);
        self::assertSame('broadcast.format.markdown.reserved', $problem->message);
        self::assertSame(['{{ char }}' => '.', '{{ line }}' => '2'], $problem->parameters);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidMarkdown(): iterable
    {
        yield 'exclamation' => ['Hello!', 'broadcast.format.markdown.reserved', '!'];
        yield 'dash' => ['10 - 20', 'broadcast.format.markdown.reserved', '-'];
        yield 'parenthesis' => ['call us (now)', 'broadcast.format.markdown.reserved', '('];
        yield 'single pipe' => ['a | b', 'broadcast.format.markdown.reserved', '|'];
        yield 'greater than mid-line' => ['a > b', 'broadcast.format.markdown.reserved', '>'];
        yield 'unclosed bold' => ['*bold', 'broadcast.format.markdown.unclosed', '*'];
        yield 'unclosed code' => ['`code', 'broadcast.format.markdown.unclosed', '`'];
        yield 'unclosed pre' => ["```\ncode`", 'broadcast.format.markdown.unclosed', '```'];
        yield 'link without its end' => ['[site](https://example.com', 'broadcast.format.markdown.link', ''];
        yield 'reserved inside bold' => ['*v1.0*', 'broadcast.format.markdown.reserved', '.'];
    }

    #[DataProvider('invalidMarkdown')]
    public function testInvalidMarkdownV2(string $text, string $message, string $char): void
    {
        $problem = BroadcastFormat::problem($text, 'MarkdownV2');

        self::assertNotNull($problem, $text);
        self::assertSame($message, $problem->message, $text);
        if ('' !== $char) {
            self::assertSame($char, $problem->parameters['{{ char }}'], $text);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validMarkdown(): iterable
    {
        yield 'escaped dot and exclamation' => ['نسخه 2\.5 آماده است\!'];
        yield 'entities' => ['*bold* _italic_ __underline__ ~strike~ ||spoiler|| `x.y()`'];
        yield 'nested' => ['*bold _italic bold_ bold*'];
        yield 'link with dots in the address' => ['[سایت ما](https://example.com/a.b?c=d)'];
        yield 'escaped parenthesis in the address' => ['[x](https://example.com/a\(1\))'];
        yield 'pre with language and reserved characters' => ["```php\n\$a = [1, 2]; // done.\n```"];
        yield 'blockquote at the line start' => [">quoted\n>more"];
        yield 'expandable blockquote' => ["**>hidden\n>lines||"];
        yield 'escaped backslash' => ['C:\\\\temp'];
        yield 'backslash before a Persian letter' => ['\\س'];
        yield 'custom emoji' => ['![👍](tg://emoji?id=5368324170671202286)'];
    }

    #[DataProvider('validMarkdown')]
    public function testValidMarkdownV2(string $text): void
    {
        self::assertNull(BroadcastFormat::problem($text, 'MarkdownV2'), $text);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function invalidHtml(): iterable
    {
        yield 'unclosed tag' => ['<b>bold', 'broadcast.format.html.unclosed', ['{{ tag }}' => 'b']];
        yield 'crossed tags' => ['<b><i>x</b></i>', 'broadcast.format.html.mismatched', ['{{ tag }}' => 'b', '{{ expected }}' => 'i']];
        yield 'end tag without start' => ['x</i>', 'broadcast.format.html.unexpected_end', ['{{ tag }}' => 'i']];
        yield 'unsupported tag' => ['line<br>break', 'broadcast.format.html.unsupported', ['{{ tag }}' => 'br']];
        yield 'bare less-than' => ['1 < 2', 'broadcast.format.html.bare_lt', []];
        yield 'start tag never closed' => ['<b class="x"', 'broadcast.format.html.unclosed_start', []];
        yield 'span without the spoiler class' => ['<span>x</span>', 'broadcast.format.html.span', []];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('invalidHtml')]
    public function testInvalidHtml(string $text, string $message, array $parameters): void
    {
        $problem = BroadcastFormat::problem($text, 'HTML');

        self::assertNotNull($problem, $text);
        self::assertSame($message, $problem->message, $text);
        foreach ($parameters as $name => $value) {
            self::assertSame($value, $problem->parameters[$name] ?? null, $text);
        }
    }

    public function testValidHtml(): void
    {
        foreach ([
            '<b>سلام</b> <i>x</i> <u>u</u> <s>s</s> <tg-spoiler>s</tg-spoiler> <span class="tg-spoiler">s</span>',
            '<a href="https://example.com/?a=1&amp;b=2">لینک</a> 1 &lt; 2 &amp; 3 > 2',
            '<pre><code class="language-php">$a = 1;</code></pre> <blockquote expandable>q</blockquote>',
            '<B>upper case</B> AT&T',
        ] as $text) {
            self::assertNull(BroadcastFormat::problem($text, 'HTML'), $text);
        }
    }

    public function testEscapingTurnsPlainTextIntoValidMarkdownV2(): void
    {
        $plain = "تخفیف ۲۰٪ تا 15.11! نسخه 2.5.1 (جدید)\nسایت: https://example.com/a_b?x=1&y=2#top\nقیمت: 1,500.00 - 2,000.00 تومان + مالیات = کل\nC:\\temp *star* [x] {y} ~z~ `c` > | .";

        $escaped = BroadcastFormat::escapeMarkdownV2($plain);

        self::assertNull(BroadcastFormat::problem($escaped, 'MarkdownV2'), $escaped);
        self::assertStringContainsString('15\.11\!', $escaped);
        self::assertStringContainsString('2\.5\.1 \(جدید\)', $escaped);
        self::assertStringContainsString('https://example\.com/a\_b?x\=1&y\=2\#top', $escaped);
        self::assertStringContainsString('1,500\.00 \- 2,000\.00', $escaped);
        self::assertStringContainsString('C:\\\\temp', $escaped, 'a backslash is escaped too, so it stays one');
        self::assertStringContainsString('تخفیف ۲۰٪', $escaped, 'Persian text and digits are untouched');
        self::assertSame($plain, self::unescape($escaped), 'Telegram shows exactly the original text');
    }

    public function testEscapeIsOfferedOnlyForMarkdownV2ThatDoesNotParse(): void
    {
        self::assertTrue(BroadcastFormat::canEscape('نسخه 2.5', 'MarkdownV2'));
        self::assertFalse(BroadcastFormat::canEscape('نسخه 2\.5', 'MarkdownV2'), 'already valid');
        self::assertFalse(BroadcastFormat::canEscape('نسخه 2.5', 'HTML'));
        self::assertFalse(BroadcastFormat::canEscape('نسخه 2.5', null));
    }

    public function testTelegramParseErrorsAreRecognised(): void
    {
        self::assertTrue(BroadcastFormat::isParseError('Telegram API returned ok=false on method "sendMessage": Bad Request: can\'t parse entities: Character \'.\' is reserved and must be escaped with the preceding \'\\\''));
        self::assertTrue(BroadcastFormat::isParseError('Bad Request: Can\'t parse entities: Unsupported start tag "br" at byte offset 5'));
        self::assertFalse(BroadcastFormat::isParseError('Forbidden: bot was blocked by the user'));
        self::assertFalse(BroadcastFormat::isParseError(null));
    }

    /** What Telegram shows for escaped MarkdownV2 without entities: each "\x" is x. */
    private static function unescape(string $text): string
    {
        return (string) preg_replace('/\\\\([\x01-\x7e])/', '$1', $text);
    }
}
