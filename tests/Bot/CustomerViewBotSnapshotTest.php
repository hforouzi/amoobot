<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Branding\BrandSubscriptionPages;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\MiniApp\Application\MiniAppLinks;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Golden files of everything the customer sees about a VPN service in the bot, recorded before PM1d
 * (docs/design/product-modules.md 9): the services list, each state's detail message and inline keyboard
 * (TelegramKeyboardFactory::userServiceDetail) with the subscription page button off (no public DEFAULT_URI) and on
 * (brand domain), renew/add-traffic allowed or not, resend config, and the delivery after a purchase, a renewal and
 * added traffic (PaymentCustomerNotifier, VpnAccessMessageFormatter). Only the customer's chat is recorded.
 */
final class CustomerViewBotSnapshotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use BrandDomainEnvironment;
    use CustomerViewFixtures;

    protected function setUp(): void
    {
        // The kernel boots in start(): a test with the subscription page button needs the brand domain first.
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    private function start(bool $pageButton = false): void
    {
        if ($pageButton) {
            $this->useBrandDomain();
        }
        parent::setUp();
        $this->setUpCustomerView();
        if ($pageButton) {
            $this->viewSetting(BrandSubscriptionPages::ENABLED, 'true');
            // With the page on its domain the Mini App button is there too (web_app), as a shop with both has it.
            $this->viewSetting(MiniAppLinks::ENABLED_SETTING, 'true');
        }
    }

    public function testTheServicesList(): void
    {
        $this->start();
        foreach (self::STATES as $state) {
            $this->stateService($state);
        }
        $this->http->reset();

        $this->pressButton(self::VIEW_CUSTOMER, 'my_services');

        CustomerViewSnapshot::assertTranscript('bot-services-list', $this->transcript(), $this->now, $this->serviceNames);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function states(): iterable
    {
        foreach (self::STATES as $state) {
            yield $state => [$state, false];
            yield $state.' with page button' => [$state, true];
        }
    }

    #[DataProvider('states')]
    public function testTheServiceDetailAndItsKeyboard(string $state, bool $pageButton): void
    {
        $this->start($pageButton);
        $service = $this->stateService($state);
        $this->http->reset();

        $this->pressButton(self::VIEW_CUSTOMER, 'service_view:'.$service->getId());

        CustomerViewSnapshot::assertTranscript('bot-detail-'.$state.($pageButton ? '-page' : ''), $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testRenewAndAddTrafficNotAllowed(): void
    {
        $this->start();
        $service = $this->stateService('active');
        $this->viewSetting(SalesSettingsProvider::RENEWALS_ENABLED, 'false');
        $this->viewSetting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'false');
        $this->http->reset();
        $this->pressButton(self::VIEW_CUSTOMER, 'service_view:'.$service->getId());
        $this->viewSetting(SalesSettingsProvider::RENEWALS_ENABLED, 'true');
        $this->pressButton(self::VIEW_CUSTOMER, 'service_view:'.$service->getId());

        CustomerViewSnapshot::assertTranscript('bot-detail-sales-closed', $this->transcript(), $this->now, $this->serviceNames);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resendStates(): iterable
    {
        foreach (['active', 'expired', 'multi_inbound', 'imported'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('resendStates')]
    public function testResendConfigAndTheSubscriptionLink(string $state): void
    {
        $this->start();
        $service = $this->stateService($state);
        $this->http->reset();

        $this->pressButton(self::VIEW_CUSTOMER, 'service_resend_config:'.$service->getId());
        $this->pressButton(self::VIEW_CUSTOMER, 'service_subscription:'.$service->getId());

        CustomerViewSnapshot::assertTranscript('bot-resend-'.$state, $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testTheDeliveryAfterAPurchase(): void
    {
        $this->start(true);
        $order = $this->createOrder($this->viewCustomer, $this->viewPlan);
        $payment = $this->createPayment($order);
        $this->http->reset();

        $this->settle($payment);

        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
        $this->nameService($service, 'NEW');
        $this->opaque += $this->credentials($service, 'NEW');
        $this->opaque[(string) $order->getTrackingCode()] = 'TRACKING_NEW';
        CustomerViewSnapshot::assertTranscript('bot-delivery-purchase', $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testTheDeliveryAfterARenewal(): void
    {
        $this->start(true);
        $service = $this->stateService('near_expiry');
        $order = $this->createOrder($this->viewCustomer, $this->viewPlan, OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order);
        $this->http->reset();

        $this->settle($payment);

        $this->opaque[(string) $order->getTrackingCode()] = 'TRACKING_RENEWAL';
        CustomerViewSnapshot::assertTranscript('bot-delivery-renewal', $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testTheDeliveryAfterAddedTraffic(): void
    {
        $this->start(true);
        $service = $this->stateService('traffic_exhausted');
        $order = $this->createOrder($this->viewCustomer, $this->viewPlan, OrderType::ADD_TRAFFIC, 30000, $service);
        $payment = $this->createPayment($order);
        $this->http->reset();

        $this->settle($payment);

        $this->opaque[(string) $order->getTrackingCode()] = 'TRACKING_ADD_TRAFFIC';
        CustomerViewSnapshot::assertTranscript('bot-delivery-add-traffic', $this->transcript(), $this->now, $this->serviceNames);
    }

    private function settle(Payment $payment): void
    {
        $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, null, null, 'test');
        self::assertTrue($result->isConfirmed(), $result->customerMessage());
        $this->em->clear();
        $order = $this->em->find(Order::class, $payment->getOrder()->getId());
        self::assertNotNull($order);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(VpnService $service, string $name): array
    {
        $values = [];
        foreach (['USERNAME' => $service->getUsername(), 'EMAIL' => $service->getClientEmail(), 'UUID' => $service->getClientUuid(), 'SUB_ID' => $service->getSubId(), 'REMOTE_ID' => $service->getRemoteId()] as $kind => $value) {
            if (null !== $value && '' !== $value) {
                $values[$value] = $kind.'_'.$name;
            }
        }

        return $values;
    }
}
