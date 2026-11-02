<?php

declare(strict_types=1);

namespace App\Tests\Branding;

use App\Branding\SupportLinkSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * support.url accepts only https://t.me/<username> or https://telegram.me/<username> (5 to 32 letters, digits or _,
 * nothing after it) or '' (no button), and is always given out as https://t.me/<username>.
 */
final class SupportLinkSettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 't.me' => ['https://t.me/shop_support', 'https://t.me/shop_support'];
        yield 'telegram.me, given out as t.me' => ['https://telegram.me/shop_support', 'https://t.me/shop_support'];
        yield 'mixed-case username kept' => ['https://t.me/Shop_Support_1', 'https://t.me/Shop_Support_1'];
        yield 'shortest username' => ['https://t.me/abcde', 'https://t.me/abcde'];
        yield 'longest username' => ['https://t.me/'.str_repeat('a', 32), 'https://t.me/'.str_repeat('a', 32)];
        yield 'digits only' => ['https://t.me/12345', 'https://t.me/12345'];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $value, string $canonical): void
    {
        self::assertTrue(SupportLinkSettings::isValid($value));
        self::assertSame($canonical, SupportLinkSettings::canonical($value));
    }

    public function testEmptyMeansNoButton(): void
    {
        self::assertTrue(SupportLinkSettings::isValid(''));
        self::assertNull(SupportLinkSettings::canonical(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript with a t.me comment' => ['javascript://t.me/shop_support%0Aalert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'tg scheme' => ['tg://resolve?domain=shop_support'];
        yield 'http' => ['http://t.me/shop_support'];
        yield 'protocol-relative' => ['//t.me/shop_support'];
        yield 'no scheme' => ['t.me/shop_support'];
        yield 'at-name' => ['@shop_support'];
        yield 'other host' => ['https://evil.example/shop_support'];
        yield 'look-alike host' => ['https://t.me.evil.example/shop_support'];
        yield 'subdomain' => ['https://www.t.me/shop_support'];
        yield 'host as userinfo' => ['https://t.me@evil.example/shop_support'];
        yield 'credentials' => ['https://user:pass@t.me/shop_support'];
        yield 'port' => ['https://t.me:443/shop_support'];
        yield 'upper-case host' => ['https://T.ME/shop_support'];
        yield 'mixed-case host' => ['https://Telegram.Me/shop_support'];
        yield 'upper-case scheme' => ['HTTPS://t.me/shop_support'];
        yield 'trailing dot host' => ['https://t.me./shop_support'];
        yield 'query string' => ['https://t.me/shop_support?start=promo'];
        yield 'empty query' => ['https://t.me/shop_support?'];
        yield 'fragment' => ['https://t.me/shop_support#x'];
        yield 'trailing slash' => ['https://t.me/shop_support/'];
        yield 'second path segment' => ['https://t.me/shop_support/12'];
        yield 'joinchat' => ['https://t.me/+AbCdEfGhIjK'];
        yield 'leading space' => [' https://t.me/shop_support'];
        yield 'trailing space' => ['https://t.me/shop_support '];
        yield 'trailing newline' => ["https://t.me/shop_support\n"];
        yield 'tab inside' => ["https://t.me/shop\tsupport"];
        yield 'NUL byte' => ["https://t.me/shop_support\0"];
        yield 'too short username' => ['https://t.me/abcd'];
        yield 'too long username' => ['https://t.me/'.str_repeat('a', 33)];
        yield 'very long' => ['https://t.me/'.str_repeat('a', 5000)];
        yield 'hyphen' => ['https://t.me/shop-support'];
        yield 'dot' => ['https://t.me/shop.support'];
        yield 'percent-encoded' => ['https://t.me/shop%5Fsupport'];
        yield 'non-ASCII letters' => ['https://t.me/پشتیبانی_فروشگاه'];
        yield 'full-width letters' => ['https://t.me/ｓｈｏｐ_support'];
        yield 'right-to-left override' => ["https://t.me/shop_\u{202E}troppus"];
        yield 'zero-width space' => ["https://t.me/shop_\u{200B}support"];
        yield 'backslash' => ['https:\\\\t.me\\shop_support'];
        yield 'quote' => ['https://t.me/shop_support"onclick="x'];
    }

    #[DataProvider('refused')]
    public function testRefused(string $value): void
    {
        self::assertFalse(SupportLinkSettings::isValid($value));
        self::assertNull(SupportLinkSettings::canonical($value));
    }
}
