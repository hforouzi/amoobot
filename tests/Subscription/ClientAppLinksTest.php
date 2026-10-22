<?php

declare(strict_types=1);

namespace App\Tests\Subscription;

use App\Entity\ClientApp;
use App\Entity\VpnService;
use App\Subscription\Application\ClientAppCatalog;
use App\Subscription\Domain\ClientPlatform;
use App\Subscription\Domain\ImportLinkTemplate;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * One-tap import buttons: the seeded client apps and their (verified) URL schemes, placeholder encoding, template
 * validation, platform ordering and what the subscription page renders for them, fallbacks included.
 */
final class ClientAppLinksTest extends IntegrationTestCase
{
    /** A subscription URL with characters that must be encoded inside a query parameter. */
    private const URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a?format=b64&x=a b';
    private const NAME = 'Gold ۱ & co';
    private const URL_ENC = 'https%3A%2F%2Fsub.example-shop.com%3A2096%2Fsub%2Fk3j9x7q2m1zt5w8a%3Fformat%3Db64%26x%3Da%20b';
    private const NAME_ENC = 'Gold%20%DB%B1%20%26%20co';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '10.79.'.random_int(0, 255).'.'.random_int(1, 254));
    }

    /**
     * name => [template, platforms, active, link built for URL / NAME]
     *
     * @return array<string, array{?string, list<string>, bool, ?string}>
     */
    public static function seededApps(): array
    {
        return [
            // Verified in the apps' own source / docs (see the seeding migration for the references).
            'Hiddify' => ['hiddify://install-config?url={url_encoded}#{name}', ['android', 'ios', 'windows', 'macos', 'linux'], true, 'hiddify://install-config?url='.self::URL_ENC.'#'.self::NAME_ENC],
            'v2rayNG' => ['v2rayng://install-sub?url={url_encoded}#{name}', ['android'], true, 'v2rayng://install-sub?url='.self::URL_ENC.'#'.self::NAME_ENC],
            'NekoBox' => ['sn://subscription?url={url_encoded}&name={name}', ['android'], true, 'sn://subscription?url='.self::URL_ENC.'&name='.self::NAME_ENC],
            // No URL scheme: install + copy the link.
            'v2rayN' => [null, ['windows', 'linux', 'macos'], true, null],
            // Scheme verified, but the app only reads sing-box profiles, not a v2ray subscription.
            'sing-box' => ['sing-box://import-remote-profile?url={url_encoded}#{name}', ['android', 'ios', 'macos'], false, 'sing-box://import-remote-profile?url='.self::URL_ENC.'#'.self::NAME_ENC],
            // Not verifiable from the vendor: inactive until an admin tests them on a device.
            'V2Box' => ['v2box://install-sub?url={url_encoded}&name={name}', ['ios', 'android'], false, 'v2box://install-sub?url='.self::URL_ENC.'&name='.self::NAME_ENC],
            'Streisand' => ['streisand://import/{url}#{name}', ['ios', 'macos'], false, 'streisand://import/'.self::URL.'#'.self::NAME_ENC],
            'Shadowrocket' => ['sub://{url_base64}#{name}', ['ios'], false, 'sub://'.base64_encode(self::URL).'#'.self::NAME_ENC],
            'FoXray' => ['foxray://yiguo.dev/sub/add/?url={url_encoded}#{name}', ['ios', 'macos'], false, 'foxray://yiguo.dev/sub/add/?url='.self::URL_ENC.'#'.self::NAME_ENC],
            'Happ' => ['happ://add/{url}', ['android', 'ios', 'windows', 'macos', 'linux'], false, 'happ://add/'.self::URL],
        ];
    }

    /**
     * @param list<string> $platforms
     */
    #[DataProvider('seededApps')]
    public function testEverySeededAppIsThereWithItsSchemeAndBuildsItsLink(?string $template, array $platforms, bool $active, ?string $expected): void
    {
        $name = (string) $this->dataName();
        $app = $this->em->getRepository(ClientApp::class)->findOneBy(['name' => $name]);

        self::assertInstanceOf(ClientApp::class, $app, $name.' is seeded');
        self::assertSame($template, $app->getImportUrlTemplate());
        self::assertSame($platforms, $app->getPlatforms());
        self::assertSame($active, $app->isActive());
        self::assertSame($expected, ImportLinkTemplate::build($app->getImportUrlTemplate(), self::URL, self::NAME));
        self::assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($app), $name.' passes validation');
    }

    public function testPlaceholdersAreEncodedForTheirPlace(): void
    {
        self::assertSame(
            'x://a?u='.rawurlencode(self::URL).'&r='.self::URL.'&b='.base64_encode(self::URL).'&n='.rawurlencode(self::NAME),
            ImportLinkTemplate::build('x://a?u={url_encoded}&r={url}&b={url_base64}&n={name}', self::URL, self::NAME),
        );
        self::assertNull(ImportLinkTemplate::build(null, self::URL, self::NAME));
        self::assertNull(ImportLinkTemplate::build('  ', self::URL, self::NAME));
    }

    public function testUnsafeOrUselessTemplatesAreRejected(): void
    {
        foreach (['javascript:alert({url})', 'JavaScript://x/{url}', 'data:text/html,{url}', 'vbscript://{url}', 'file:///{url}', 'hiddify://import/', 'no scheme {url}', 'https://evil.example/?u={url}"><script>'] as $template) {
            self::assertNotNull(ImportLinkTemplate::problem($template), $template);
            $app = (new ClientApp())->setName('Bad')->setPlatforms(['android'])->setImportUrlTemplate($template);
            self::assertGreaterThan(0, count(self::getContainer()->get(ValidatorInterface::class)->validate($app)), $template);
        }
        self::assertNull(ImportLinkTemplate::problem('hiddify://install-config?url={url_encoded}#{name}'));
        self::assertNull(ImportLinkTemplate::problem(null), 'no deep link is allowed (copy-only app)');

        $app = (new ClientApp())->setName('Bad store')->setPlatforms(['android'])->setStoreUrl('javascript:alert(1)');
        self::assertGreaterThan(0, count(self::getContainer()->get(ValidatorInterface::class)->validate($app)), 'store URL must be http(s)');
        $app = (new ClientApp())->setName('No platform')->setPlatforms([]);
        self::assertGreaterThan(0, count(self::getContainer()->get(ValidatorInterface::class)->validate($app)), 'at least one platform');
    }

    public function testAppsForTheVisitorsPlatformComeFirstAndInactiveOnesAreHidden(): void
    {
        $catalog = self::getContainer()->get(ClientAppCatalog::class);

        $android = $catalog->forPage(self::URL, self::NAME, ClientPlatform::ANDROID);
        self::assertSame(['Hiddify', 'v2rayNG', 'NekoBox', 'v2rayN'], array_map(static fn ($a): string => $a->name, $android));
        self::assertSame([true, true, true, false], array_map(static fn ($a): bool => $a->matchesPlatform, $android));

        $ios = $catalog->forPage(self::URL, self::NAME, ClientPlatform::IOS);
        self::assertSame(['Hiddify', 'v2rayNG', 'NekoBox', 'v2rayN'][0], $ios[0]->name);
        self::assertSame([true, false, false, false], array_map(static fn ($a): bool => $a->matchesPlatform, $ios));

        $windows = $catalog->forPage(self::URL, self::NAME, ClientPlatform::WINDOWS);
        self::assertSame(['Hiddify', 'v2rayN'], array_slice(array_map(static fn ($a): string => $a->name, $windows), 0, 2));

        self::assertSame(['Hiddify', 'v2rayNG', 'NekoBox', 'v2rayN'], array_map(static fn ($a): string => $a->name, $catalog->forPage(self::URL, self::NAME, null)), 'unknown platform: admin order');
    }

    public function testThePlatformIsGuessedFromTheUserAgent(): void
    {
        $cases = [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36 Telegram-Android/11.2.0' => ClientPlatform::ANDROID,
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' => ClientPlatform::IOS,
            'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X)' => ClientPlatform::IOS,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0' => ClientPlatform::WINDOWS,
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15' => ClientPlatform::MACOS,
            'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/127.0' => ClientPlatform::LINUX,
            'curl/8.0' => null,
            '' => null,
        ];
        foreach ($cases as $ua => $expected) {
            self::assertSame($expected, ClientPlatform::fromUserAgent($ua), $ua);
        }
    }

    public function testThePageRendersInstallAndAddButtonsWithFallbacks(): void
    {
        $service = $this->service();

        $this->client->request('GET', '/s/'.$service->getPublicToken(), [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) Telegram-Android/11.2.0']);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $expected = htmlspecialchars('v2rayng://install-sub?url='.rawurlencode('https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a').'#'.rawurlencode('Test plan'), ENT_QUOTES);
        self::assertStringContainsString('href="'.$expected.'"', $html, 'add-subscription deep link, no redirect');
        self::assertStringContainsString('href="https://github.com/2dust/v2rayNG/releases"', $html, 'install link');
        self::assertStringContainsString('data-open-external', $html, 'open-in-browser fallback');
        self::assertStringContainsString('data-copy="https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a"', $html, 'copy fallback');
        self::assertStringNotContainsString('http-equiv="refresh"', $html, 'no automatic redirect');
        self::assertStringNotContainsString('location.href', $html, 'no automatic redirect');
        self::assertStringNotContainsString('sing-box://', $html, 'inactive apps are not shown');
        // Android visitor: the Android apps are in the first (matching) group.
        self::assertLessThan(strpos($html, 'v2rayN<'), strpos($html, 'NekoBox<'));
    }

    public function testThePlatformCanBeChosenOnThePage(): void
    {
        $service = $this->service();

        $this->client->request('GET', '/s/'.$service->getPublicToken(), ['platform' => 'windows'], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14)']);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-platform="windows"', $html);
        $this->client->request('GET', '/s/'.$service->getPublicToken(), ['platform' => '<script>']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'unknown platform is ignored');
    }

    private function service(): VpnService
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound())
            ->setSubscriptionUrl('https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a')
            ->setSubId('k3j9x7q2m1zt5w8a');
        $service->setOrder($this->createOrder($service->getUser(), $this->createPlan()));
        $this->em->flush();

        return $service;
    }
}
