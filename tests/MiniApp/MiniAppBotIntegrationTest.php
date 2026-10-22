<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\Setting;
use App\MiniApp\Application\MiniAppLinks;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RouterInterface;

/**
 * The bot's ways into the Mini App: "Open app" buttons (main menu, service view), shown only with the setting
 * miniapp.enabled and a public HTTPS address, and app:telegram:set-menu-button for the chat menu button.
 */
final class MiniAppBotIntegrationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '8501';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [MiniAppLinks::ENABLED_SETTING]);
    }

    public function testNoAppButtonsUntilTheSettingIsOn(): void
    {
        $this->publicHost();
        $service = $this->createActiveService($this->createUser(self::CUSTOMER));

        $this->sendText(self::CUSTOMER, '/start');
        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertNotContains($this->botButton('button.main.miniapp'), $this->replyButtons());
        self::assertSame([], $this->webAppUrls());
    }

    public function testMainMenuOffersTheAppAndItsButtonOpensTheMiniApp(): void
    {
        $this->publicHost();
        $this->enable();
        $this->createUser(self::CUSTOMER);

        $this->sendText(self::CUSTOMER, '/start');
        self::assertContains($this->botButton('button.main.miniapp'), $this->replyButtons(), 'kept alongside the other main menu buttons');
        self::assertContains($this->botButton('button.main.my_services'), $this->replyButtons());

        $this->http->reset();
        $this->sendText(self::CUSTOMER, $this->botButton('button.main.miniapp'));

        // An inline web_app button: unlike a reply keyboard web_app button it gives the app initData to sign in with.
        self::assertSame(['https://shop.example.com/app'], $this->webAppUrls());
    }

    public function testTheServiceViewOpensThatServiceInTheAppNextToTheExistingButtons(): void
    {
        $this->publicHost();
        $this->enable();
        $service = $this->createActiveService($this->createUser(self::CUSTOMER));

        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertSame(['https://shop.example.com/app?service='.$service->getId()], $this->webAppUrls());
        self::assertContains('service_resend_config:'.$service->getId(), $this->callbacks(), 'existing buttons stay');
        self::assertContains('https://shop.example.com/s/'.$service->getPublicToken(), $this->urlButtons(), 'subscription page button stays');
    }

    public function testNoAppButtonWithoutAPublicHttpsAddress(): void
    {
        $this->enable();
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('http')->setHost('shop.example.com');
        $service = $this->createActiveService($this->createUser(self::CUSTOMER));

        $this->sendText(self::CUSTOMER, '/start');
        $this->pressButton(self::CUSTOMER, 'service_view:'.$service->getId());

        self::assertNotContains($this->botButton('button.main.miniapp'), $this->replyButtons(), 'Telegram only opens HTTPS Mini Apps');
        self::assertSame([], $this->webAppUrls());
    }

    public function testSetMenuButtonDryRunShowsThePayloadAndCallsNothing(): void
    {
        $this->publicHost();
        $tester = $this->command();

        self::assertSame(0, $tester->execute(['--dry-run' => true]));

        self::assertStringContainsString('https://shop.example.com/app', $tester->getDisplay());
        self::assertStringContainsString('web_app', $tester->getDisplay());
        self::assertSame([], $this->http->requests('/setChatMenuButton'));
    }

    public function testSetMenuButtonSetsAndRemovesTheBotsMenuButton(): void
    {
        $this->publicHost();

        self::assertSame(0, $this->command()->execute([]));
        $body = $this->requestBody($this->http->requests('/setChatMenuButton')[0]);
        self::assertSame('web_app', $body['menu_button']['type']);
        self::assertSame('https://shop.example.com/app', $body['menu_button']['web_app']['url']);
        self::assertSame($this->botButton('button.miniapp.menu'), $body['menu_button']['text']);

        $this->http->reset();
        self::assertSame(0, $this->command()->execute(['--remove' => true]));
        self::assertSame(['type' => 'default'], $this->requestBody($this->http->requests('/setChatMenuButton')[0])['menu_button']);
    }

    public function testSetMenuButtonRefusesAnAddressTelegramCannotOpen(): void
    {
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('http')->setHost('localhost');
        $tester = $this->command();

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('DEFAULT_URI', $tester->getDisplay());
        self::assertSame([], $this->http->requests('/setChatMenuButton'));
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:telegram:set-menu-button'));
    }

    private function enable(): void
    {
        $this->em->persist((new Setting())->setKeyName(MiniAppLinks::ENABLED_SETTING)->setValue('true')->setType('boolean'));
        $this->em->flush();
    }

    private function publicHost(): void
    {
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('https')->setHost('shop.example.com');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function markups(): array
    {
        $markups = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            if (is_array($markup)) {
                $markups[] = $markup;
            }
        }

        return $markups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inlineButtons(): array
    {
        $buttons = [];
        foreach ($this->markups() as $markup) {
            foreach ($markup['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $buttons[] = $button;
                }
            }
        }

        return $buttons;
    }

    /**
     * @return list<string>
     */
    private function webAppUrls(): array
    {
        return array_values(array_map(static fn (array $b): string => (string) $b['web_app']['url'], array_filter($this->inlineButtons(), static fn (array $b): bool => isset($b['web_app']))));
    }

    /**
     * @return list<string>
     */
    private function urlButtons(): array
    {
        return array_values(array_map(static fn (array $b): string => (string) $b['url'], array_filter($this->inlineButtons(), static fn (array $b): bool => isset($b['url']))));
    }

    /**
     * @return list<string>
     */
    private function callbacks(): array
    {
        return array_values(array_map(static fn (array $b): string => (string) $b['callback_data'], array_filter($this->inlineButtons(), static fn (array $b): bool => isset($b['callback_data']))));
    }

    /**
     * Texts of the reply keyboard buttons sent since the last reset (no web_app ones: those would open without initData).
     *
     * @return list<string>
     */
    private function replyButtons(): array
    {
        $texts = [];
        foreach ($this->markups() as $markup) {
            foreach ($markup['keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    self::assertArrayNotHasKey('web_app', $button, 'a reply keyboard web_app button would open without initData');
                    $texts[] = (string) $button['text'];
                }
            }
        }

        return $texts;
    }
}
