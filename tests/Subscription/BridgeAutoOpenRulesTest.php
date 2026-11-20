<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Subscription\Domain\BridgeAutoOpen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * When the import bridge opens the app by itself, and with what (BridgeAutoOpen).
 */
final class BridgeAutoOpenRulesTest extends TestCase
{
    private const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    private const FALLBACK = 'https://brand.example.org/s/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/open/3?noauto=1';

    /**
     * @return iterable<string, array{string, ?string, bool, bool}>
     */
    public static function requests(): iterable
    {
        yield 'Android browser' => ['GET', self::ANDROID_CHROME, false, true];
        yield 'iPhone browser' => ['GET', self::IPHONE_SAFARI, false, true];
        yield 'HEAD' => ['HEAD', self::ANDROID_CHROME, false, false];
        yield 'noauto' => ['GET', self::ANDROID_CHROME, true, false];
        yield 'no user agent' => ['GET', null, false, false];
        yield 'empty user agent' => ['GET', '  ', false, false];
        yield 'Googlebot' => ['GET', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', false, false];
        yield 'Telegram link preview' => ['GET', 'TelegramBot (like TwitterBot)', false, false];
        yield 'WhatsApp preview' => ['GET', 'WhatsApp/2.23.20.0', false, false];
        yield 'Facebook preview' => ['GET', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', false, false];
        yield 'bingbot' => ['GET', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', false, false];
        yield 'curl' => ['GET', 'curl/8.5.0', false, false];
        yield 'headless Chrome' => ['GET', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0 Safari/537.36', false, false];
    }

    #[DataProvider('requests')]
    public function testWhenTheBridgeTriesByItself(string $method, ?string $userAgent, bool $optedOut, bool $expected): void
    {
        self::assertSame($expected, BridgeAutoOpen::allowed($method, $userAgent, $optedOut));
    }

    public function testAndroidWithAPackageGetsAnIntentWithTheFallbackToThisPage(): void
    {
        $target = BridgeAutoOpen::target('hiddify://install-config?url=https%3A%2F%2Fsub.example.com%2Fsub%2Fx#My%20VPN', 'app.hiddify.com', true, self::FALLBACK);

        self::assertSame('intent://install-config?url=https%3A%2F%2Fsub.example.com%2Fsub%2Fx#My%20VPN#Intent;scheme=hiddify;package=app.hiddify.com;S.browser_fallback_url='.rawurlencode(self::FALLBACK).';end', $target);
        self::assertSame(strrpos($target, '#Intent;'), strrpos($target, '#'), 'Android reads the intent from the last "#": the fallback adds none');
    }

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function plain(): iterable
    {
        yield 'not Android' => ['v2rayng://install-sub?url=x', 'com.v2ray.ang', false];
        yield 'no package' => ['v2rayng://install-sub?url=x', null, true];
        yield 'not a package name' => ['v2rayng://install-sub?url=x', 'v2rayng;end', true];
        yield 'no // after the scheme' => ['sub:abc', 'com.example.app', true];
        yield 'an https link' => ['https://example.com/import', 'com.example.app', true];
        yield 'already an intent' => ['intent://x#Intent;end', 'com.example.app', true];
    }

    #[DataProvider('plain')]
    public function testOtherwiseThePlainImportLink(string $importUrl, ?string $package, bool $android): void
    {
        self::assertSame($importUrl, BridgeAutoOpen::target($importUrl, $package, $android, self::FALLBACK));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function storeUrls(): iterable
    {
        yield 'Google Play' => ['https://play.google.com/store/apps/details?id=com.v2ray.ang', 'com.v2ray.ang'];
        yield 'Google Play with a language' => ['https://play.google.com/store/apps/details?id=app.hiddify.com&hl=en', 'app.hiddify.com'];
        yield 'GitHub releases' => ['https://github.com/2dust/v2rayNG/releases', null];
        yield 'App Store' => ['https://apps.apple.com/app/id6450534064', null];
        yield 'http' => ['http://play.google.com/store/apps/details?id=com.v2ray.ang', null];
        yield 'another host' => ['https://play.google.com.evil.example/store/apps/details?id=com.v2ray.ang', null];
        yield 'not a package' => ['https://play.google.com/store/apps/details?id=com.v2ray.ang;end', null];
        yield 'no id' => ['https://play.google.com/store/apps/details', null];
        yield 'none' => [null, null];
    }

    #[DataProvider('storeUrls')]
    public function testThePackageComesFromAGooglePlayLink(?string $storeUrl, ?string $expected): void
    {
        self::assertSame($expected, BridgeAutoOpen::androidPackage($storeUrl));
    }
}
