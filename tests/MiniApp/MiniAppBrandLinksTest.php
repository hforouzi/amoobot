<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Entity\Setting;
use App\MiniApp\Application\MiniAppLinks;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every URL that opens the Mini App follows BRAND_PUBLIC_BASE_URL together: set and valid they use the brand base from
 * any context, empty or invalid they are what they always were.
 */
final class MiniAppBrandLinksTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;

    private const CUSTOMER = '9201';

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MiniAppLinks::ENABLED_SETTING]);
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    protected function setUp(): void
    {
        // The kernel boots per test, in start(): each test chooses its own environment.
    }

    private function start(?string $brand): MiniAppLinks
    {
        $this->useBrandDomain($brand);
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MiniAppLinks::ENABLED_SETTING]);
        $this->em->persist((new Setting())->setKeyName(MiniAppLinks::ENABLED_SETTING)->setValue('true')->setType('boolean'));
        $this->em->flush();

        return self::getContainer()->get(MiniAppLinks::class);
    }

    public function testASetBrandMovesEveryMiniAppUrl(): void
    {
        $links = $this->start(self::BRAND_BASE);

        self::assertSame(self::BRAND_BASE.'/app', $links->publicUrl());
        self::assertSame(self::BRAND_BASE.'/app?service=12', $links->publicUrl(12));
        self::assertSame(self::BRAND_BASE.'/app', $links->forTelegram());
        self::assertSame(self::BRAND_BASE.'/app?service=12', $links->forTelegram(12));
        self::assertSame(self::BRAND_BASE.'/app?guide=connect', $links->forGuide('connect'));

        $keyboard = self::getContainer()->get(TelegramKeyboardFactory::class)->miniAppOpen();
        self::assertSame(self::BRAND_BASE.'/app', $keyboard['inline_keyboard'][0][0]['web_app']['url'] ?? null);
    }

    public function testTheBrandBaseHoldsInsideARequestOnTheOperationalHost(): void
    {
        $links = $this->start(self::BRAND_BASE);
        $request = Request::create(self::OPERATIONAL_BASE.'/telegram/webhook/x', 'POST');
        self::getContainer()->get(RequestStack::class)->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->fromRequest($request);

        self::assertSame(self::BRAND_BASE.'/app', $links->forTelegram());
    }

    public function testAMiniAppOnThePortOfTheBrandKeepsIt(): void
    {
        self::assertSame(self::BRAND_BASE.':8443/app', $this->start(self::BRAND_BASE.':8443')->publicUrl());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noBrand(): iterable
    {
        yield 'empty' => [null];
        yield 'invalid: http' => ['http://brand.example.org'];
        yield 'invalid: a path' => [self::BRAND_BASE.'/app'];
        yield 'invalid: the DEFAULT_URI host' => [self::OPERATIONAL_BASE];
    }

    #[DataProvider('noBrand')]
    public function testEmptyOrInvalidIsTheRouterAnswerAsToday(?string $brand): void
    {
        $links = $this->start($brand);

        self::assertSame(self::OPERATIONAL_BASE.'/app', $links->publicUrl(), 'DEFAULT_URI outside a request');
        self::assertSame(self::OPERATIONAL_BASE.'/app?guide=connect', $links->forGuide('connect'));

        // As always: inside a request the router answers with that request's host.
        $request = Request::create('https://other.example.net/x');
        self::getContainer()->get(RouterInterface::class)->getContext()->fromRequest($request);
        self::assertSame('https://other.example.net/app', $links->publicUrl());
    }

    public function testTheMenuButtonCommandSendsTheBrandUrl(): void
    {
        $this->start(self::BRAND_BASE);
        $this->http->reset();
        $this->http->onJson('/setChatMenuButton', ['ok' => true, 'result' => true]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:set-menu-button'));
        self::assertSame(0, $tester->execute([]));

        $requests = $this->http->requests('/setChatMenuButton');
        self::assertCount(1, $requests);
        self::assertSame(self::BRAND_BASE.'/app', $this->requestBody($requests[0])['menu_button']['web_app']['url']);
    }

    public function testTheBotsGuideDeepLinkOpensOnTheBrand(): void
    {
        $this->start(self::BRAND_BASE);
        $this->createUser(self::CUSTOMER);
        $this->http->reset();

        $this->sendText(self::CUSTOMER, '/start guide_connect');

        $sent = (string) json_encode(array_map($this->requestBody(...), $this->http->requests('/sendMessage')), JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString(self::BRAND_BASE.'/app?guide=connect', str_replace('\\', '', $sent));
    }
}
