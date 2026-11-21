<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\VpnService;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use App\Provisioning\Application\VpnCustomerView;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SpyProductModule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PM1d: the bot's customer service view asks the service's product module what to show (customerView()) and offer
 * (customerActions(), channel 'bot'), once each; the customer's link, QR and config buttons are run by the module
 * (runCustomerAction()), whose delivery then reads the same view once. An id that names no service, or a service that
 * isn't the customer's, gets the bot's own answer and never reaches the module. What the customer sees is pinned by
 * the snapshots (CustomerViewBotSnapshotTest); this pins the path.
 */
final class CustomerViewViaModuleTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use CustomerViewFixtures;

    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
        $this->spy = SpyProductModule::install(self::getContainer());
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    public function testTheServiceViewAsksTheModuleOnceForItsViewAndItsActions(): void
    {
        $service = $this->stateService('active');
        $id = (int) $service->getId();

        $this->pressButton(self::VIEW_CUSTOMER, 'service_view:'.$id);

        self::assertSame(['customerView', 'customerActions'], $this->spy->methods());
        $this->assertService($id, $this->spy->calls[0]['args']);
        self::assertInstanceOf(VpnCustomerView::class, $this->spy->calls[0]['result']);
        self::assertCount(2, $this->spy->calls[1]['args']);
        $this->assertService($id, [$this->spy->calls[1]['args'][0]]);
        self::assertSame(CustomerActions::CHANNEL_BOT, $this->spy->calls[1]['args'][1]);
        $actions = $this->spy->calls[1]['result'];
        self::assertInstanceOf(CustomerActions::class, $actions);
        self::assertTrue($actions->has(CustomerActions::RENEW));
        self::assertTrue($actions->has(CustomerActions::ADD_TRAFFIC));
        self::assertFalse($actions->has(CustomerActions::RESET_LINKS));
        self::assertNull($actions->pageUrl, 'no public DEFAULT_URI in the test environment: no page button');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function customerButtons(): iterable
    {
        yield 'subscription link' => ['service_subscription:%d', CustomerActions::SEND_LINK];
        yield 'subscription QR' => ['service_subscription_qr:%d', CustomerActions::SEND_QR];
        yield 'configs' => ['service_config_links:%d', CustomerActions::RESEND_CONFIG];
        yield 'resend config' => ['service_resend_config:%d', CustomerActions::RESEND_CONFIG];
    }

    #[DataProvider('customerButtons')]
    public function testACustomerButtonIsRunByTheModuleOnce(string $callback, string $action): void
    {
        $service = $this->stateService('active');
        $id = (int) $service->getId();

        $this->pressButton(self::VIEW_CUSTOMER, sprintf($callback, $id));

        // The spy records a call when it returns: the view the delivery read, then the action that ran it.
        self::assertSame(['customerView', 'runCustomerAction'], $this->spy->methods());
        $args = $this->spy->calls[1]['args'];
        self::assertCount(3, $args);
        $this->assertService($id, [$args[0]]);
        self::assertSame($action, $args[1]);
        $input = $args[2];
        self::assertInstanceOf(CustomerActionInput::class, $input);
        self::assertSame(self::VIEW_CUSTOMER, (string) $input->account->getTelegramId());
        self::assertSame(self::VIEW_CUSTOMER, $input->chatId);
        self::assertStringStartsWith('cb', $input->callbackId);
        self::assertFalse($this->spy->calls[1]['result']->failed);
        $this->assertService($id, $this->spy->calls[0]['args']);
        self::assertNotSame([], $this->sentTexts(), 'the delivery reached the chat');
    }

    public function testAnUnknownServiceAndSomeoneElsesServiceNeverReachTheModule(): void
    {
        $other = $this->stateService('active', $this->createUser('7302'));
        $otherId = (int) $other->getId();

        foreach (['service_view:%d', 'service_subscription:%d', 'service_subscription_qr:%d', 'service_config_links:%d', 'service_resend_config:%d'] as $callback) {
            $this->pressButton(self::VIEW_CUSTOMER, sprintf($callback, 99999999));
            $this->pressButton(self::VIEW_CUSTOMER, sprintf($callback, $otherId));
        }

        // Unknown ids: no module call at all. Someone else's service: the module runs the action, whose delivery
        // refuses it before it reads anything (the bot's owner check), so no view is asked for.
        self::assertSame(['runCustomerAction', 'runCustomerAction', 'runCustomerAction', 'runCustomerAction'], $this->spy->methods());
        foreach ($this->spy->calls as $call) {
            $this->assertService($otherId, [$call['args'][0]]);
        }
        self::assertContains('دسترسی غیرمجاز.', $this->callbackAlerts());
        self::assertContains('سرویس معتبر نیست.', $this->callbackAlerts());
    }

    /**
     * @param list<mixed> $args
     */
    private function assertService(int $id, array $args): void
    {
        self::assertCount(1, $args);
        self::assertInstanceOf(VpnService::class, $args[0]);
        self::assertSame($id, $args[0]->getId());
    }

    /** @return list<string> */
    private function callbackAlerts(): array
    {
        return array_map(fn (array $request): string => (string) ($this->requestBody($request)['text'] ?? ''), $this->http->requests('/answerCallbackQuery'));
    }
}
