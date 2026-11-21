<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\TelegramAccount;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use App\Provisioning\Application\VpnProductModule;
use App\Tests\Support\IntegrationTestCase;

/**
 * PM1d: the 'vpn' module's customer surface refuses what it does not know (a channel, an action) with
 * \InvalidArgumentException, as runLifecycleTask() does for a task, and its view is bound to the service it was asked
 * for. What each channel shows and offers is pinned by the customer-view snapshots.
 */
final class VpnProductModuleCustomerTest extends IntegrationTestCase
{
    private VpnProductModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $module = self::getContainer()->get('test.module.vpn_product_module');
        self::assertInstanceOf(VpnProductModule::class, $module);
        $this->module = $module;
    }

    public function testTheViewIsBoundToTheService(): void
    {
        $service = $this->createActiveService($this->createUser('8831'), $this->createTestInbound());

        self::assertSame($service, $this->module->customerView($service)->service());
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $service = $this->createActiveService($this->createUser('8832'), $this->createTestInbound());

        $this->expectException(\InvalidArgumentException::class);
        $this->module->customerActions($service, 'web');
    }

    public function testAnUnknownActionIsRefusedBeforeAnythingRuns(): void
    {
        $user = $this->createUser('8833');
        $service = $this->createActiveService($user, $this->createTestInbound());
        $account = $user->getTelegramAccount();
        self::assertInstanceOf(TelegramAccount::class, $account);

        try {
            $this->module->runCustomerAction($service, 'reboot', new CustomerActionInput($account, '8833'));
            self::fail('no exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"reboot"', $e->getMessage());
        }
        self::assertSame([], $this->http->requests('/sendMessage'), 'nothing was sent');
    }

    public function testBothChannelsOfferTheDeliveryActions(): void
    {
        $service = $this->createActiveService($this->createUser('8834'), $this->createTestInbound());

        foreach ([CustomerActions::CHANNEL_BOT, CustomerActions::CHANNEL_MINIAPP] as $channel) {
            $actions = $this->module->customerActions($service, $channel);
            foreach ([CustomerActions::SEND_LINK, CustomerActions::SEND_QR, CustomerActions::RESEND_CONFIG, CustomerActions::REFRESH] as $action) {
                self::assertTrue($actions->has($action), $channel.' '.$action);
            }
        }
    }
}
