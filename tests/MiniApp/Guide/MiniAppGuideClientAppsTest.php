<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Entity\BotMessageTemplate;
use App\MiniApp\Guide\GuidePreview;
use App\MiniApp\Guide\GuideTextRules;
use App\Tests\Support\GuideClientAppFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The guide's "Install and connect" topic with the admin's client apps (G-3, templates/miniapp/view/_guide_apps.html.twig):
 * the device from the User-Agent or the customer's switch, the first active app as the recommended one, the others in
 * a smaller line, install links only from the admin's data (escaped, http(s) only, opened with openLink), never an
 * import or subscription link, and the generic text without any app name when there is no app for the device.
 */
final class MiniAppGuideClientAppsTest extends IntegrationTestCase
{
    use GuideClientAppFixtures;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36 Telegram-Android/11.2';
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    private const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
    private const LINUX = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
        $this->switchOffClientApps();
    }

    protected function tearDown(): void
    {
        $this->restoreClientApps();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%'");
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function userAgents(): iterable
    {
        yield 'iPhone' => [self::IPHONE, 'ios'];
        yield 'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'ios'];
        yield 'Android' => [self::ANDROID, 'android'];
        yield 'Windows' => [self::WINDOWS, 'windows'];
        yield 'macOS' => [self::MAC, 'macos'];
        yield 'Linux' => [self::LINUX, 'linux'];
        yield 'unknown' => ['curl/8.7.1', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('userAgents')]
    public function testTheDeviceComesFromTheUserAgent(string $userAgent, ?string $platform): void
    {
        $this->everyPlatform();

        $apps = $this->apps('/app/view/help?lang=en&topic=connect', $userAgent);
        self::assertSame($platform ?? '', $apps->attr('data-guide-apps'));
        self::assertSame(null === $platform ? [] : [$platform], $apps->filter('[data-guide-platform].current')->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-platform')));
        if (null === $platform) {
            self::assertCount(1, $apps->filter('[data-guide-apps-choose]'), 'unknown device: asked to choose');
            self::assertCount(0, $apps->filter('[data-guide-app-recommended], [data-guide-install], [data-guide-apps-generic]'));
        } else {
            self::assertSame('App for '.$platform, $apps->filter('[data-guide-app-recommended] .app-name')->text());
        }
    }

    public function testEveryDeviceHasASwitchThatKeepsTheConnectTopicOpen(): void
    {
        $this->everyPlatform();

        $html = $this->help('/app/view/help?lang=en', self::ANDROID);
        $chips = (new Crawler($html))->filter('[data-guide-platforms] a');
        self::assertSame(['android', 'ios', 'windows', 'macos', 'linux'], $chips->each(static fn (Crawler $c): string => (string) $c->attr('data-guide-platform')));
        self::assertSame(['Android', 'iOS', 'Windows', 'macOS', 'Linux'], $chips->each(static fn (Crawler $c): string => $c->text()));
        $chips->each(static function (Crawler $chip): void {
            self::assertSame('/app/view/help?topic=connect&platform='.$chip->attr('data-guide-platform'), $chip->attr('href'));
            self::assertTrue(null !== $chip->attr('data-nav') && null !== $chip->attr('data-replace'), 'an in-place switch, no script of its own');
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function platforms(): iterable
    {
        foreach (['android', 'ios', 'windows', 'macos', 'linux'] as $platform) {
            yield $platform => [$platform];
        }
    }

    #[DataProvider('platforms')]
    public function testTheSwitchShowsThatDevicesAppsWhateverTheUserAgent(string $platform): void
    {
        $this->everyPlatform();

        $html = $this->help('/app/view/help?lang=en&topic=connect&platform='.$platform, self::IPHONE);
        $apps = (new Crawler($html))->filter('[data-guide-apps]');
        self::assertSame($platform, $apps->attr('data-guide-apps'));
        self::assertSame('App for '.$platform, $apps->filter('[data-guide-app-recommended] .app-name')->text());
        self::assertSame('https://store.example/'.$platform, $apps->filter('[data-guide-install]')->attr('href'));
        self::assertNotNull((new Crawler($html))->filter('details[data-guide-topic="connect"]')->attr('open'), 'the switch opens the topic');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oddPlatforms(): iterable
    {
        yield 'unknown value' => ['platform=symbian'];
        yield 'upper case' => ['platform=IOS'];
        yield 'array' => ['platform[]=ios'];
        yield 'markup' => ['platform=%3Cscript%3E'];
    }

    #[DataProvider('oddPlatforms')]
    public function testAnOddSwitchValueFallsBackToTheUserAgent(string $query): void
    {
        $this->everyPlatform();

        $html = $this->help('/app/view/help?lang=en&topic=connect&'.$query, self::WINDOWS);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('windows', (new Crawler($html))->filter('[data-guide-apps]')->attr('data-guide-apps'));
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testTheFirstAppIsRecommendedAndTheOthersAreInASmallerLine(): void
    {
        $this->addClientApp('Gamma Client', ['android'], 30, 'https://store.example/gamma');
        $this->addClientApp('Alpha Client', ['android', 'ios'], 10, 'https://store.example/alpha', icon: '🟢');
        $this->addClientApp('Beta Client', ['android'], 20, null);
        $this->addClientApp('Hidden Client', ['android'], 5, 'https://store.example/hidden', active: false);

        $apps = $this->apps('/app/view/help?lang=en&topic=connect', self::ANDROID);

        $recommended = $apps->filter('[data-guide-app-recommended]');
        self::assertCount(1, $recommended);
        self::assertSame('Alpha Client', $recommended->filter('.app-name')->text());
        self::assertStringContainsString('🟢', $recommended->text());
        self::assertSame('Recommended', $recommended->filter('[data-guide-recommended]')->text());
        self::assertSame(['Beta Client', 'Gamma Client'], $apps->filter('[data-guide-apps-others] [data-guide-other]')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('span', $apps->filter('[data-guide-other]')->eq(0)->nodeName(), 'no install link: the name without a link');
        self::assertSame('https://store.example/gamma', $apps->filter('[data-guide-other]')->eq(1)->attr('href'));
        self::assertStringNotContainsString('Hidden Client', $apps->html(), 'an inactive app is not shown');
        self::assertStringNotContainsString('hidden', (string) $apps->attr('data-guide-apps'));
    }

    public function testInstallLinksOpenWithTelegramAndKeepNothingOfTheReferrer(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha?id=com.alpha');
        $this->addClientApp('Beta Client', ['android'], 20, 'https://store.example/beta');

        $apps = $this->apps('/app/view/help?lang=en&topic=connect', self::ANDROID);
        $links = $apps->filter('[data-guide-install], a[data-guide-other]');
        self::assertCount(2, $links);
        $links->each(static function (Crawler $link): void {
            self::assertSame('noopener noreferrer', $link->attr('rel'));
            self::assertSame('_blank', $link->attr('target'), 'a plain link in a new tab without the script');
            self::assertNotNull($link->attr('data-open-link'), 'Telegram.WebApp.openLink in the Mini App');
        });
        self::assertSame('https://store.example/alpha?id=com.alpha', $apps->filter('[data-guide-install]')->attr('href'), 'exactly the admin\'s link: no tracking parameter');
    }

    public function testADownloadOfTheAppIsLinkedThroughTheCounter(): void
    {
        $app = $this->addClientApp('Alpha Client', ['windows'], 10, 'https://store.example/alpha');
        $download = $this->addDownload($app, 'windows', 'https://downloads.example/alpha-setup.exe');

        $apps = $this->apps('/app/view/help?lang=en&topic=connect', self::WINDOWS);
        self::assertSame('/d/'.$download->getId(), $apps->filter('[data-guide-install]')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(document.cookie)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'attribute break-out' => ['https://store.example/x"onmouseover="alert(1)'];
        yield 'credentials' => ['https://admin:secret@store.example/x'];
        yield 'app scheme' => ['zeta://import/x'];
    }

    #[DataProvider('hostileUrls')]
    public function testAHostileInstallUrlIsNotRendered(string $url): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, $url);
        $this->addClientApp('Beta Client', ['android'], 20, $url);

        $html = $this->help('/app/view/help?lang=en&topic=connect', self::ANDROID);
        $apps = (new Crawler($html))->filter('[data-guide-apps]');
        self::assertSame('Alpha Client', $apps->filter('[data-guide-app-recommended] .app-name')->text(), 'the app is still named');
        self::assertCount(0, $apps->filter('a[data-guide-install], a[data-guide-other]'));
        self::assertCount(1, $apps->filter('[data-guide-no-link]'));
        foreach (['javascript:', 'data:text', 'onmouseover', 'secret', 'zeta://'] as $piece) {
            self::assertStringNotContainsString($piece, $html);
        }
    }

    public function testAppNamesAndIconsAreEscaped(): void
    {
        $this->addClientApp('<img src=x onerror=alert(1)>', ['android'], 10, 'https://store.example/a', icon: '"><b>x</b>');
        $this->addClientApp('<script>alert(2)</script>', ['android'], 20, 'https://store.example/b');

        $html = $this->help('/app/view/help?lang=en&topic=connect', self::ANDROID);
        $apps = (new Crawler($html))->filter('[data-guide-apps]');
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>alert(2)', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertSame('<img src=x onerror=alert(1)>', $apps->filter('[data-guide-app-recommended] .app-name')->text());
        self::assertCount(0, $apps->filter('img, script, b'));
    }

    public function testAnAppWithAnImportButtonOnlyPointsToMyServices(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha', importTemplate: 'zeta://import/{url_encoded}#{name}');
        $service = $this->createActiveService($this->user);
        $service->setSubscriptionUrl('https://sub.panel-shop.example:2096/sub/k3j9x7q2m1zt5w8a');
        $this->em->flush();

        $html = $this->help('/app/view/help?lang=en&topic=connect', self::ANDROID);
        $hint = (new Crawler($html))->filter('[data-guide-import-hint]');
        self::assertCount(1, $hint);
        self::assertSame('/app/view/services', $hint->filter('a[data-nav]')->attr('href'));
        foreach (['zeta', 'k3j9x7q2m1zt5w8a', 'panel-shop', '/s/', 'url_encoded'] as $piece) {
            self::assertStringNotContainsString($piece, $html, 'no import or subscription link in the guide');
        }
    }

    public function testWithoutImportButtonsThereIsNoPointer(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha');

        self::assertCount(0, $this->apps('/app/view/help?lang=en&topic=connect', self::ANDROID)->filter('[data-guide-import-hint]'));
    }

    public function testNoAppAtAllShowsTheGenericTextWithoutSwitchOrButtons(): void
    {
        $this->addClientApp('Switched Off', ['android', 'ios'], 10, 'https://store.example/off', active: false);

        foreach (['fa' => '/app/view/help?topic=connect', 'en' => '/app/view/help?lang=en&topic=connect'] as $locale => $path) {
            $html = $this->help($path, self::ANDROID);
            $connect = (new Crawler($html))->filter('details[data-guide-topic="connect"]');
            $apps = $connect->filter('[data-guide-apps]');
            self::assertCount(1, $apps->filter('[data-guide-apps-generic]'), $locale);
            self::assertNotSame('', trim($apps->filter('[data-guide-apps-generic]')->text()), $locale.': never an empty block');
            self::assertCount(0, $apps->filter('a, button, [data-guide-platforms]'), $locale.': no switch and no button');
            self::assertStringNotContainsString('Switched Off', $html);
            self::assertStringNotContainsString('://', $html, $locale.': no link of any app');
            self::assertCount(4, $connect->filter('.guide-body h3'), $locale.': the four steps are still there');
        }
    }

    public function testNoAppForTheChosenDeviceShowsTheGenericTextWithTheSwitch(): void
    {
        $this->addClientApp('Phone Client', ['android', 'ios'], 10, 'https://store.example/phone');

        $apps = $this->apps('/app/view/help?lang=en&topic=connect&platform=linux', self::ANDROID);
        self::assertSame('linux', $apps->attr('data-guide-apps'));
        self::assertCount(1, $apps->filter('[data-guide-apps-generic]'));
        self::assertCount(5, $apps->filter('[data-guide-platforms] a'), 'the customer can switch to a device with apps');
        self::assertCount(0, $apps->filter('[data-guide-app-recommended], [data-guide-install]'));
        self::assertStringNotContainsString('Phone Client', $apps->html());
        self::assertStringContainsString('Ask support', $apps->text());
    }

    public function testTheGenericTextNamesNoApp(): void
    {
        foreach (['fa' => '/app/view/help?topic=connect', 'en' => '/app/view/help?lang=en&topic=connect'] as $locale => $path) {
            $text = (new Crawler($this->help($path, self::ANDROID)))->filter('details[data-guide-topic="connect"]')->text();
            self::assertDoesNotMatchRegularExpression('/\bhapp\b|v2box|hiddify|v2rayng|nekobox|sing-?box|streisand|shadowrocket|foxray|v2rayn/i', $text, $locale);
        }
    }

    public function testThePageIsTheSameForEveryCustomer(): void
    {
        $this->everyPlatform();
        $this->addClientApp('Importer', ['android'], 1, 'https://store.example/importer', importTemplate: 'zeta://import/{url}');
        $service = $this->createActiveService($this->user);
        $service->setSubscriptionUrl('https://sub.panel-shop.example:2096/sub/k3j9x7q2m1zt5w8a');
        $this->em->flush();

        foreach (['/app/view/help?lang=en', '/app/view/help?topic=connect&platform=ios'] as $path) {
            $mine = $this->help($path, self::ANDROID, self::CUSTOMER);
            $other = $this->help($path, self::ANDROID, '8699');
            self::assertSame($mine, $other, $path);
            foreach (['k3j9x7q2m1zt5w8a', 'panel-shop', 'zeta', $this->botToken(), self::CARD_NUMBER] as $secret) {
                self::assertStringNotContainsString($secret, $mine);
            }
        }
    }

    public function testThePersianPageIsRightToLeftWithTheAppNamesIsolated(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha');
        $this->addClientApp('Beta Client', ['android'], 20, 'https://store.example/beta');

        $html = $this->help('/app/view/help?topic=connect', self::ANDROID);
        $apps = (new Crawler($html))->filter('[data-guide-apps]');
        self::assertSame('پیشنهادی', $apps->filter('[data-guide-recommended]')->text());
        self::assertSame('اندروید', $apps->filter('[data-guide-platform].current')->text());
        self::assertSame('Alpha Client', $apps->filter('[data-guide-app-recommended] bdi')->text());
        self::assertSame('Beta Client', $apps->filter('[data-guide-apps-others] bdi')->text());
    }

    public function testTheAppsStandWhereTheTextPutsThemOrOnTop(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha');

        $body = $this->connectBody();
        self::assertLessThan(strpos($body, '2. Copy your link'), strpos($body, 'data-guide-apps'), 'the shipped text: under step 1');
        self::assertGreaterThan(strpos($body, '1. Install an app'), strpos($body, 'data-guide-apps'));

        $this->guideRow('guide.connect.body', 'en', "## Before\nText\n\n{{ apps }}\n\n## After");
        $body = $this->connectBody();
        self::assertTrue(strpos($body, 'Before') < strpos($body, 'data-guide-apps') && strpos($body, 'data-guide-apps') < strpos($body, 'After'));

        $this->guideRow('guide.connect.body', 'en', "## My own text\nWithout the placeholder.");
        $body = $this->connectBody();
        self::assertSame(1, substr_count($body, 'data-guide-apps="android"'));
        self::assertLessThan(strpos($body, 'My own text'), strpos($body, 'data-guide-apps'), 'on top');

        $this->guideRow('guide.connect.body', 'en', '');
        self::assertCount(0, (new Crawler($this->help('/app/view/help?lang=en', self::ANDROID)))->filter('[data-guide-topic="connect"], [data-guide-apps]'), 'an emptied topic stays hidden');
    }

    public function testTheAppsPlaceholderIsOnlyForTheConnectText(): void
    {
        self::assertSame([], GuideTextRules::problems('guide.connect.body', "## 1\n{{ apps }}\n"));
        self::assertContains('guide_text.apps_only_connect', array_column(GuideTextRules::problems('guide.start.body', "Hi\n{{ apps }}\n"), 'message'));
        self::assertContains('guide_text.placeholder_own_line', array_column(GuideTextRules::problems('guide.connect.body', 'Apps: {{ apps }}'), 'message'));

        // A text that slipped through (edited in the database) shows no apps outside "connect".
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha');
        $this->guideRow('guide.start.body', 'en', "Start here.\n{{ apps }}");
        $start = (new Crawler($this->help('/app/view/help?lang=en', self::ANDROID)))->filter('details[data-guide-topic="start"]');
        self::assertCount(0, $start->filter('[data-guide-apps]'));
        self::assertStringNotContainsString('{{', $start->html());
    }

    public function testTheAdminPreviewShowsWhereTheAppsGo(): void
    {
        $this->addClientApp('Alpha Client', ['android'], 10, 'https://store.example/alpha');

        $preview = self::getContainer()->get(GuidePreview::class)->preview('guide.connect.body', 'en', "## 1. Install\n{{ apps }}\n\n## 2. Copy");
        $frame = new Crawler($preview['document']);
        self::assertCount(1, $frame->filter('[data-preview-apps]'));
        self::assertStringNotContainsString('Alpha Client', $preview['document'], 'not one device\'s list');
        self::assertSame([], $preview['problems']);
    }

    /** One app per platform, named after it, sorted so each is first for its platform. */
    private function everyPlatform(): void
    {
        foreach (['android', 'ios', 'windows', 'macos', 'linux'] as $i => $platform) {
            $this->addClientApp('App for '.$platform, [$platform], 10 + $i, 'https://store.example/'.$platform);
        }
    }

    private function help(string $path, string $userAgent, string $customer = self::CUSTOMER): string
    {
        // The session is bound to the device it was opened on: log in with the same User-Agent.
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $session = $this->authenticate($customer);
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token']]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function apps(string $path, string $userAgent): Crawler
    {
        $apps = (new Crawler($this->help($path, $userAgent)))->filter('details[data-guide-topic="connect"] [data-guide-apps]');
        self::assertCount(1, $apps);

        return $apps;
    }

    private function connectBody(): string
    {
        return (new Crawler($this->help('/app/view/help?lang=en', self::ANDROID)))->filter('details[data-guide-topic="connect"] .guide-body')->html();
    }

    private function guideRow(string $key, string $locale, string $body): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM bot_message_template WHERE template_key = ? AND locale = ?', [$key, $locale]);
        $this->em->persist((new BotMessageTemplate())->setKey($key)->setLocale($locale)->setTitle($key)->setBody($body)->setParseMode('guide')
            ->setCategory('guide')->setIsActive(true)->setIsSystem(false));
        $this->em->flush();
    }
}
