<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Reseller\Domain\ResellerException;
use App\Shop\Application\Checkout\NewServiceCheckout;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanSaleRule;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Domain\WalletException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * F1 (M2c): one sale rule for plans with additional inbounds (PlanSaleRule over MultiInboundSale::blocker()), the same
 * in every channel: sold only with plans.multi_inbound_enabled on, every inbound active, all on the primary's panel and
 * that panel 3x-ui in modern mode. Checked where a plan is listed or chosen (PlanCatalog: bot, Mini App, resellers),
 * where a chosen plan becomes an order (draft confirmation) and where an order is paid (gateway / card payment start,
 * wallet payment), the last two by the order's inbound snapshot. Plans without additional inbounds and renewals are
 * not affected.
 */
final class MultiInboundSaleRuleTest extends IntegrationTestCase
{
    use ResellerFixtures;

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $primary;
    private VpnInbound $vmess;
    private Plan $multi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$this->panel, $this->primary, $this->vmess] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->multi = $this->createPlan(200000, $this->primary)->setTitle('Multi')->setAdditionalInbounds([$this->vmess]);
        $this->em->flush();
    }

    /**
     * @return iterable<string, array{\Closure(self): void, bool}>
     */
    public static function states(): iterable
    {
        yield 'switch off' => [static function (self $t): void {}, false];
        yield 'switch on, everything usable' => [static fn (self $t) => $t->switchOn(), true];
        yield 'an additional inbound inactive' => [static function (self $t): void {
            $t->switchOn();
            $t->sql('UPDATE vpn_inbound SET is_active = 0 WHERE id = ?', [$t->vmess->getId()]);
        }, false];
        yield 'the primary inactive' => [static function (self $t): void {
            $t->switchOn();
            $t->sql('UPDATE vpn_inbound SET is_active = 0 WHERE id = ?', [$t->primary->getId()]);
        }, false];
        yield 'an additional inbound on another panel' => [static function (self $t): void {
            $t->switchOn();
            $t->sql("INSERT INTO vpn_panel (type, title, base_url, is_active, created_at) VALUES ('sanaei_3xui', 'other', 'https://other.test/', 1, NOW())");
            $t->sql('UPDATE vpn_inbound SET panel_id = LAST_INSERT_ID() WHERE id = ?', [$t->vmess->getId()]);
        }, false];
        yield 'the panel switched to legacy' => [static function (self $t): void {
            $t->switchOn();
            $t->sql("UPDATE vpn_panel SET sanaei_integration_mode = 'legacy' WHERE id = ?", [$t->panel->getId()]);
        }, false];
        yield 'the panel degraded' => [static function (self $t): void {
            $t->switchOn();
            $t->sql('UPDATE vpn_panel SET degraded_since = NOW() WHERE id = ?', [$t->panel->getId()]);
        }, false];
    }

    #[DataProvider('states')]
    public function testTheCatalogTheMiniAppAndResellersApplyTheRule(\Closure $state, bool $sellable): void
    {
        $state($this);
        $this->em->clear();
        $planId = (int) $this->multi->getId();
        $single = (int) $this->plan->getId();

        $catalog = self::getContainer()->get(PlanCatalog::class);
        self::assertSame($sellable ? [$single, $planId] : [$single], array_map(static fn (Plan $p): ?int => $p->getId(), $catalog->forSale()), 'bot and Mini App lists (PlanCatalog::forSale)');
        self::assertSame($sellable, null !== $catalog->findForSale($planId), 'bot plan choice and payment-method step (PlanCatalog::findForSale)');
        $plan = $this->em->find(Plan::class, $planId) ?? self::fail('plan');
        self::assertSame($sellable, self::getContainer()->get(PlanSaleRule::class)->isSellable($plan));

        try {
            self::getContainer()->get(MiniAppCheckout::class)->plan($planId);
            self::assertTrue($sellable, 'the Mini App checkout accepted it');
        } catch (CheckoutFailure $e) {
            self::assertFalse($sellable);
            self::assertSame('plan_unavailable', $e->error);
        }

        $offers = array_map(static fn (array $o): ?int => $o['plan']->getId(), $this->checkout()->newServiceOffers($this->user)['offers']);
        self::assertSame($sellable, in_array($planId, $offers, true), 'reseller offers');
        try {
            $this->checkout()->quoteNewService($this->user, $planId);
            self::assertTrue($sellable, 'the reseller quote accepted it');
        } catch (ResellerException $e) {
            self::assertFalse($sellable);
            self::assertSame('plan_unavailable', $e->error);
        }
    }

    public function testAnOrderStartedWhileSellableIsRefusedAtConfirmationPaymentAndWallet(): void
    {
        $this->switchOn();
        $draft = self::getContainer()->get(NewServiceCheckout::class)->startDraft($this->user, $this->multi, 'test', false)
            ->setFinalUsername('multi1')->setCalculatedAmount(200000)->setFinalAmount(200000);
        $this->em->flush();
        $order = $this->createOrder($this->user, $this->multi, amount: 200000);
        // The additional inbound turns inactive (inbound sync) before the customer confirms or pays.
        $this->sql('UPDATE vpn_inbound SET is_active = 0 WHERE id = ?', [$this->vmess->getId()]);
        $this->em->clear();
        $draft = $this->em->find(OrderDraft::class, $draft->getId()) ?? self::fail('draft');
        $order = $this->em->find(Order::class, $order->getId()) ?? self::fail('order');

        self::assertNull(self::getContainer()->get(NewServiceCheckout::class)->createOrderFromDraft($draft), 'draft confirmation');
        $start = self::getContainer()->get(PaymentStarter::class)->start($order, $this->card());
        self::assertSame(PaymentStart::KIND_PLAN_INACTIVE, $start->kind, 'gateway / card payment start');
        $this->fund(500000);
        try {
            self::getContainer()->get(WalletPayment::class)->pay($order);
            self::fail('the wallet paid an order of a plan that is not sold');
        } catch (WalletException $e) {
            self::assertSame('plan_unavailable', $e->error);
        }
        self::assertSame(500000, $this->balance(), 'nothing debited');
    }

    public function testTheSwitchTurnedOffBeforePaymentRefusesTheOrder(): void
    {
        $this->switchOn();
        $order = $this->createOrder($this->user, $this->multi, amount: 200000);
        $this->setting('plans.multi_inbound_enabled', '0');
        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? self::fail('order');

        self::assertSame(PaymentStart::KIND_PLAN_INACTIVE, self::getContainer()->get(PaymentStarter::class)->start($order, $this->card())->kind);
    }

    public function testPaymentFollowsTheOrdersSnapshotNotThePlanEditedSince(): void
    {
        // Ordered while the plan had one inbound; an inactive additional inbound added later does not block paying it.
        $order = $this->createOrder($this->user, $this->plan);
        $inactive = (new VpnInbound())->setPanel($this->em->find(VpnPanel::class, $this->inbound->getPanel()->getId()) ?? self::fail('panel'))->setRemoteInboundId('9')->setTitle('late')->setIsActive(false);
        $this->em->persist($inactive);
        $this->plan->setAdditionalInbounds([$inactive]);
        $this->em->flush();
        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? self::fail('order');

        self::assertTrue(self::getContainer()->get(PlanSaleRule::class)->acceptsPayment($order));
        self::assertNotSame(PaymentStart::KIND_PLAN_INACTIVE, self::getContainer()->get(PaymentStarter::class)->start($order, $this->card())->kind);
    }

    public function testARenewalOfAMultiInboundServiceIsPaidWhateverTheSwitch(): void
    {
        $renewal = $this->createOrder($this->user, $this->multi, OrderType::RENEWAL);
        $this->em->clear();
        $renewal = $this->em->find(Order::class, $renewal->getId()) ?? self::fail('order');

        self::assertTrue(self::getContainer()->get(PlanSaleRule::class)->acceptsPayment($renewal), 'switch off');
        self::assertNotSame(PaymentStart::KIND_PLAN_INACTIVE, self::getContainer()->get(PaymentStarter::class)->start($renewal, $this->card())->kind);
    }

    public function testAPlanWithoutAdditionalInboundsIsSoldAsBeforeWhateverTheSwitch(): void
    {
        foreach (['0', '1'] as $switch) {
            $this->setting('plans.multi_inbound_enabled', $switch);
            $this->em->clear();
            $plan = $this->em->find(Plan::class, $this->plan->getId()) ?? self::fail('plan');
            $rule = self::getContainer()->get(PlanSaleRule::class);
            self::assertTrue($rule->isSellable($plan), 'switch '.$switch);
            self::assertTrue($rule->acceptsOrders($plan), 'switch '.$switch);
        }
    }

    public function testAResellerBuysAMultiInboundPlanAtThePlansWholesalePrice(): void
    {
        $this->switchOn();
        $this->fund(1000000);

        $result = $this->buy((int) $this->multi->getId());

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);
        self::assertSame([1, 2], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame(['attached'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound'));
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE plan_id = ?', [$this->multi->getId()]));
        self::assertSame(1000000 - 160000, $this->balance(), 'the plan price minus the reseller discount (20 %), as for any plan');
    }

    private function card(): StorePaymentMethod
    {
        return $this->em->find(StorePaymentMethod::class, $this->methods['manual_card']->getId()) ?? self::fail('method');
    }

    public function switchOn(): void
    {
        $this->setting('plans.multi_inbound_enabled', '1');
    }

    /**
     * @param list<mixed> $parameters
     */
    public function sql(string $sql, array $parameters = []): void
    {
        $this->em->getConnection()->executeStatement($sql, $parameters);
    }
}
