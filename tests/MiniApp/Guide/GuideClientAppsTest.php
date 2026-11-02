<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\MiniApp\Guide\GuideClientApp;
use App\MiniApp\Guide\GuideClientApps;
use App\Subscription\Domain\ClientPlatform;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GuideClientApps (G-3): per platform the active apps in the admin's sort order (the first is the recommended one),
 * install links only from the admin's data and only http(s), nothing for an unknown platform, and the platforms
 * without any active app for the admin's warning.
 */
final class GuideClientAppsTest extends IntegrationTestCase
{
    use GuideClientAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->switchOffClientApps();
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        parent::tearDown();
    }

    public function testTheAppsOfAPlatformInTheAdminsOrder(): void
    {
        $this->addClientApp('Third', ['android'], 30, 'https://store.example/third');
        $this->addClientApp('First', ['android', 'ios'], 10, 'https://store.example/first');
        $this->addClientApp('Desktop only', ['windows', 'linux'], 5, 'https://store.example/desktop');
        $this->addClientApp('Second', ['android'], 20, null);

        self::assertSame(['First', 'Second', 'Third'], $this->names(ClientPlatform::ANDROID));
        self::assertSame(['First'], $this->names(ClientPlatform::IOS));
        self::assertSame(['Desktop only'], $this->names(ClientPlatform::WINDOWS));
        self::assertSame(['Desktop only'], $this->names(ClientPlatform::LINUX));
        self::assertSame([], $this->names(ClientPlatform::MACOS));
        self::assertSame([], $this->apps()->forPlatform(null), 'unknown device: no list');
    }

    public function testTheSameSortOrderFallsBackToTheNameLikeTheSubscriptionPage(): void
    {
        $this->addClientApp('Zulu', ['ios'], 10, null);
        $this->addClientApp('Alpha', ['ios'], 10, null);

        self::assertSame(['Alpha', 'Zulu'], $this->names(ClientPlatform::IOS));
    }

    public function testInactiveAppsAreHidden(): void
    {
        $this->addClientApp('Shown', ['android'], 20, 'https://store.example/shown');
        $this->addClientApp('Hidden', ['android'], 10, 'https://store.example/hidden', active: false);

        self::assertSame(['Shown'], $this->names(ClientPlatform::ANDROID));
        self::assertSame([], $this->names(ClientPlatform::IOS));
    }

    public function testNoActiveAppAtAll(): void
    {
        $this->addClientApp('Hidden', ['android', 'ios'], 10, 'https://store.example/hidden', active: false);

        self::assertFalse($this->apps()->any());
        self::assertSame(ClientPlatform::cases(), $this->apps()->platformsWithoutApps());
    }

    public function testPlatformsWithoutApps(): void
    {
        $this->addClientApp('Phone', ['android', 'ios'], 10, null);
        $this->addClientApp('Off', ['windows'], 10, null, active: false);

        self::assertTrue($this->apps()->any());
        self::assertSame([ClientPlatform::WINDOWS, ClientPlatform::MACOS, ClientPlatform::LINUX], $this->apps()->platformsWithoutApps());
    }

    public function testTheInstallLinkIsTheAppsDownloadForThatPlatformElseItsStoreUrl(): void
    {
        $app = $this->addClientApp('Multi', ['android', 'windows', 'ios'], 10, 'https://store.example/multi');
        $windows = $this->addDownload($app, 'windows', 'https://downloads.example/multi-setup.exe');
        $this->addDownload($app, 'ios', 'https://downloads.example/inactive', active: false);

        self::assertSame('/d/'.$windows->getId(), $this->first(ClientPlatform::WINDOWS)->installUrl, 'through the download counter');
        self::assertSame('https://store.example/multi', $this->first(ClientPlatform::ANDROID)->installUrl, 'no download for android: the store URL');
        self::assertSame('https://store.example/multi', $this->first(ClientPlatform::IOS)->installUrl, 'an inactive download is not used');
    }

    public function testABrokenLinkDownloadIsSkipped(): void
    {
        $app = $this->addClientApp('Multi', ['android'], 10, 'https://store.example/multi');
        $this->addDownload($app, 'android', 'javascript:alert(1)');

        self::assertSame('https://store.example/multi', $this->first(ClientPlatform::ANDROID)->installUrl);
    }

    public function testTheImportButtonIsOnlyAFlag(): void
    {
        $this->addClientApp('Importer', ['android'], 10, 'https://store.example/i', importTemplate: 'zeta://import/{url_encoded}');
        $this->addClientApp('Plain', ['android'], 20, 'https://store.example/p');

        $apps = $this->apps()->forPlatform(ClientPlatform::ANDROID);
        self::assertTrue($apps[0]->hasImportButton);
        self::assertFalse($apps[1]->hasImportButton);
        self::assertStringNotContainsString('zeta', serialize($apps), 'the template itself is never handed out');
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://play.google.com/store/apps/details?id=com.example.app', true];
        yield 'http' => ['http://downloads.example/app.exe', true];
        yield 'upper case scheme' => ['HTTPS://apps.example/app', true];
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'javascript with slashes' => ['javascript://example.com/%0Aalert(1)', false];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', false];
        yield 'vbscript' => ['vbscript:msgbox(1)', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'ftp' => ['ftp://example.com/app.apk', false];
        yield 'app scheme' => ['happ://add/https://sub.example/x', false];
        yield 'relative' => ['/d/1', false];
        yield 'protocol relative' => ['//evil.example/app', false];
        yield 'credentials' => ['https://user:pass@example.com/app', false];
        yield 'quote' => ['https://example.com/"onmouseover="alert(1)', false];
        yield 'angle brackets' => ['https://example.com/<script>', false];
        yield 'space' => ['https://example.com/a b', false];
        yield 'newline' => ["https://example.com/a\nb", false];
        yield 'non ascii' => ['https://exämple.com/app', false];
        yield 'no host' => ['https:///path', false];
    }

    #[DataProvider('urls')]
    public function testOnlyPlainHttpUrlsAreInstallLinks(?string $url, bool $safe): void
    {
        self::assertSame($safe, GuideClientApps::isSafeInstallUrl($url));
    }

    public function testAnUnsafeStoreUrlGivesNoLink(): void
    {
        $this->addClientApp('Hostile', ['android'], 10, 'javascript:alert(document.cookie)');

        $app = $this->first(ClientPlatform::ANDROID);
        self::assertSame('Hostile', $app->name, 'the app is still named');
        self::assertNull($app->installUrl);
    }

    private function apps(): GuideClientApps
    {
        return self::getContainer()->get(GuideClientApps::class);
    }

    /**
     * @return list<string>
     */
    private function names(ClientPlatform $platform): array
    {
        return array_map(static fn (GuideClientApp $app): string => $app->name, $this->apps()->forPlatform($platform));
    }

    private function first(ClientPlatform $platform): GuideClientApp
    {
        return $this->apps()->forPlatform($platform)[0] ?? self::fail('no app for '.$platform->value);
    }
}
