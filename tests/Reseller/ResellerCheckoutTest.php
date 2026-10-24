<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\ResellerStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletStatus;

/**
 * S1 reseller purchases (docs/design/reseller-and-claim.md, 2.3 / 2.4 / 4.3): a new service, a renewal and extra
 * traffic at wholesale, paid from the reseller's wallet through the existing wallet payment and settlement; the
 * snapshot on the order is what is paid; a refused purchase leaves nothing behind; discount codes and the global
 * discount never stack; the feature, a disabled reseller, a frozen wallet and the daily cap stop it.
 */
final class ResellerCheckoutTest extends IntegrationTestCase
{
    use ResellerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'pricing.%'");
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
    }

    public function testANewServiceIsBoughtAtWholesaleFromTheWallet(): void
    {
        $this->fund(500000);

        $result = $this->buy();

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);
        self::assertSame(500000 - 120000, $this->balance(), '150,000 - 20 %');
        $order = $this->onlyOrder();
        self::assertSame($this->reseller->getId(), $order->getReseller()?->getId());
        self::assertSame(OrderType::NEW_SERVICE, $order->getType());
        self::assertSame(OrderStatus::PROVISIONED, $order->getStatus());
        self::assertSame(120000, $order->getAmount());
        self::assertSame(120000, $order->getWholesaleAmount());
        self::assertSame(150000, $order->getRetailAmount());
        self::assertSame(75000, $order->getResellerFloorAmount(), '50 % of the base by default');
        $snapshot = $order->getMetadata()['priceSnapshot'] ?? [];
        self::assertSame(120000, $snapshot['finalAmount']);
        self::assertSame(['resellerId' => $this->reseller->getId(), 'baseAmount' => 150000, 'retailAmount' => 150000, 'rule' => 'reseller', 'discountBp' => 2000, 'candidateAmount' => 120000, 'floorAmount' => 75000, 'floorApplied' => false, 'retailApplied' => false, 'roundedTo' => 1000, 'wholesaleAmount' => 120000], $snapshot['reseller']);

        $payment = $result->payment ?? self::fail('no payment');
        self::assertTrue($payment->isWalletPayment());
        self::assertSame(120000, $payment->getPayableAmount());
        $debit = $this->wallets()->findTransaction(WalletKeys::purchase((int) $payment->getId()));
        self::assertSame(-120000, $debit?->getAmount());
        self::assertSame('purchase', $debit->getType(), 'the existing ledger type and key');

        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order]) ?? self::fail('no service');
        self::assertSame($this->user->getId(), $service->getUser()->getId(), 'created under the reseller');
        self::assertSame($this->reseller->getId(), $service->getSoldByReseller()?->getId());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testRenewalAndExtraTrafficOfASoldServiceAreWholesaleToo(): void
    {
        $this->fund(1000000);
        $this->buy();
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller]) ?? self::fail();
        $expires = $service->getExpiresAt();

        $renewal = $this->renewSold((int) $service->getId());
        self::assertSame(SettlementOutcome::CONFIRMED, $renewal->outcome);
        $this->em->refresh($service);
        self::assertGreaterThan($expires, $service->getExpiresAt());

        $traffic = $this->addTrafficSold((int) $service->getId(), 10);
        self::assertSame(SettlementOutcome::CONFIRMED, $traffic->outcome);

        $orders = $this->em->getRepository(Order::class)->findBy(['reseller' => $this->reseller], ['id' => 'ASC']);
        self::assertSame([OrderType::NEW_SERVICE, OrderType::RENEWAL, OrderType::ADD_TRAFFIC], array_map(static fn (Order $o): string => $o->getType(), $orders));
        self::assertSame(120000, $orders[1]->getAmount(), 'renewal: the plan price now, minus 20 %');
        self::assertSame(30000, $orders[2]->getRetailAmount(), '10 GB x 3,000');
        self::assertSame(24000, $orders[2]->getAmount(), 'add-traffic: the add-on price minus 20 %');
        self::assertSame(1000000 - 120000 - 120000 - 24000, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testAddTrafficUsesThePlanPercentButNotItsFixedPrice(): void
    {
        $this->fund(1000000);
        $this->buy();
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller]) ?? self::fail();

        $this->planRule($this->plan, 3000);
        [, , $quote] = $this->checkout()->quoteAddTraffic($this->user, (int) $service->getId(), 10);
        self::assertSame(21000, $quote->wholesaleAmount, '30,000 - 30 % (the plan percent)');

        $this->em->getConnection()->executeStatement('DELETE FROM reseller_plan_price');
        $this->planRule($this->plan, null, 50000);
        [, , $quote] = $this->checkout()->quoteAddTraffic($this->user, (int) $service->getId(), 10);
        self::assertSame(24000, $quote->wholesaleAmount, 'a fixed price is for the plan itself: the default 20 %');
        [, , $quote] = $this->checkout()->quoteRenewal($this->user, (int) $service->getId());
        self::assertSame(75000, $quote->wholesaleAmount, 'the renewal uses the fixed price, held up by the floor');
    }

    public function testACustomizablePlanIsPricedOnTheChosenTrafficAndDays(): void
    {
        $plan = (new Plan())->setTitle('Custom')->setDurationDays(30)->setTrafficGb(10)->setPrice(0)->setInbound($this->inbound)
            ->setIsCustomizable(true)->setMinTrafficGb(10)->setMaxTrafficGb(200)->setPricePerGb(2000)
            ->setMinDurationDays(30)->setMaxDurationDays(90)->setPricePerDay(1000)->setResellerFloorAmount(999999);
        $this->em->persist($plan);
        $this->em->flush();
        $this->planRule($plan, null, 1000);

        [, , $quote] = $this->checkout()->quoteNewService($this->user, (int) $plan->getId(), 50, 60);
        self::assertSame(160000, $quote->baseAmount, '50 x 2,000 + 60 x 1,000');
        self::assertSame(128000, $quote->wholesaleAmount, 'the default 20 %: no fixed price and no plan floor amount on a customizable plan');

        foreach ([[5, 60], [50, 100], [null, 60]] as [$traffic, $days]) {
            try {
                $this->checkout()->quoteNewService($this->user, (int) $plan->getId(), $traffic, $days);
                self::fail('out of range');
            } catch (ResellerException $e) {
                self::assertSame('invalid_options', $e->error);
            }
        }
    }

    public function testDiscountCodesAndTheGlobalDiscountNeverStack(): void
    {
        $this->fund(1000000);
        foreach ([fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), new ResellerConfirmation(120000, 'unused'), null, null, 'SUMMER'), fn () => $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId(), null, null, 'SUMMER')] as $call) {
            try {
                $call();
                self::fail('a code was accepted');
            } catch (ResellerException $e) {
                self::assertSame('discount_not_for_resellers', $e->error);
            }
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));

        // A 10 % shop-wide discount: retail 135,000. The reseller still pays base - 20 % = 120,000 (no stacking).
        $this->setting('pricing.global_discount_percent', '10');
        [, , $quote] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        self::assertSame(135000, $quote->retailAmount);
        self::assertSame(120000, $quote->wholesaleAmount);

        // A 30 % promotion: retail 105,000 is below the reseller price, so he pays retail (D3).
        $this->setting('pricing.global_discount_percent', '30');
        [, , $quote] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        self::assertSame(105000, $quote->wholesaleAmount);
        self::assertTrue($quote->retailApplied);
    }

    public function testNotEnoughMoneyLeavesNothingBehind(): void
    {
        $this->fund(119999);
        try {
            $this->buy();
            self::fail('paid without the money');
        } catch (WalletException $e) {
            self::assertSame('wallet_insufficient', $e->error);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'not even an unpaid order');
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM payment'));
        self::assertSame(119999, $this->balance());

        // The wallet credit limit (0 by default, set per reseller by an admin) counts.
        $this->wallets()->setCreditLimit($this->user, 1, WalletActor::system());
        self::assertSame(SettlementOutcome::CONFIRMED, $this->buy()->outcome);
        self::assertSame(-1, $this->balance());
    }

    public function testAFrozenWalletADisabledResellerAndTheSwitchStopIt(): void
    {
        $this->fund(1000000);

        $this->wallets()->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());
        $this->assertRefused('wallet_frozen');
        $this->wallets()->setStatus($this->user, WalletStatus::ACTIVE, WalletActor::system());

        $this->reseller->setStatus(ResellerStatus::DISABLED);
        $this->em->flush();
        $this->assertRefused('reseller_unavailable');
        self::assertNull($this->checkout()->resellerFor($this->user));
        $this->reseller->setStatus(ResellerStatus::ACTIVE);
        $this->em->flush();

        $this->setting(ResellerSettings::ENABLED, 'false');
        $this->assertRefused('reseller_unavailable');
        self::assertNull($this->checkout()->resellerFor($this->user));

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(1000000, $this->balance());
    }

    public function testTheDailyCapCountsTodaysOpenAndPaidOrders(): void
    {
        $this->fund(1000000);
        $this->reseller->setDailySpendLimit(250000);
        $this->em->flush();

        $this->buy();
        self::assertSame(130000, $this->checkout()->remainingToday($this->reseller));
        $this->buy();
        $this->assertRefused('daily_limit_reached');
        self::assertSame(2, $this->sqlInt('SELECT COUNT(*) FROM `order`'));

        // A cancelled order does not count, and neither do yesterday's.
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE `order` SET status = 'cancelled' WHERE id = (SELECT MIN(id) FROM (SELECT id FROM `order`) o)");
        self::assertSame(130000, $this->checkout()->remainingToday($this->reseller));
        self::assertSame(SettlementOutcome::CONFIRMED, $this->buy()->outcome);
        $this->assertRefused('daily_limit_reached');
        $connection->executeStatement('UPDATE `order` SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE reseller_id = ?', [$this->reseller->getId()]);
        self::assertSame(SettlementOutcome::CONFIRMED, $this->buy()->outcome);
    }

    public function testOnlyServicesHeSoldCanBeRenewed(): void
    {
        $this->fund(1000000);
        $own = $this->createActiveService($this->user, $this->inbound);
        $own->setOrder($this->createOrder($this->user, $this->plan));
        $this->em->flush();

        foreach ([fn () => $this->renewSold((int) $own->getId()), fn () => $this->addTrafficSold((int) $own->getId(), 10), fn () => $this->renewSold(999999)] as $call) {
            try {
                $call();
                self::fail('renewed a service he did not sell');
            } catch (ResellerException $e) {
                self::assertSame('service_not_sold_by_you', $e->error);
            }
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order` WHERE reseller_id IS NOT NULL'));
    }

    /**
     * Section 2.4 with review item B2: the purchase quotes again under the lock and charges only the confirmed price
     * (a rule changed in between is refused with price_changed and the price now); a payment opened at the old price
     * settles at its snapshot; a reseller order is never paid any other way (no gateway, no "pay from wallet").
     */
    public function testThePriceIsTheSnapshotOfTheMomentTheOrderIsCreated(): void
    {
        $this->fund(1000000);
        [, , $shown, $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        self::assertSame(120000, $shown->wholesaleAmount);
        $this->reseller->setDiscountBp(3000);
        $this->em->flush();
        try {
            $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $confirmation);
            self::fail('charged a price the reseller did not confirm');
        } catch (ResellerException $e) {
            self::assertSame('price_changed', $e->error);
            self::assertSame(105000, $e->quote?->wholesaleAmount, 'the price now, for a fresh confirmation');
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        $this->buy();
        self::assertSame(105000, $this->onlyOrder()->getAmount(), 'the freshly confirmed price');

        // An order opened (debited) at its snapshot, then the discount changes: it settles at the snapshot, once.
        $order = (new Order())->setUser($this->user)->setPlan($this->plan)->setType(OrderType::NEW_SERVICE)->setAmount(105000)->setStatus(OrderStatus::WAITING_PAYMENT)
            ->attributeToReseller($this->reseller, 105000, 150000, 75000)->setResellerNonce('opened-at-the-old-price')
            ->setMetadata(['trafficGb' => 50, 'durationDays' => 30, 'priceSnapshot' => ['finalAmount' => 105000, 'reseller' => ['wholesaleAmount' => 105000]]]);
        $this->em->persist($order);
        $this->em->flush();
        $walletPayment = self::getContainer()->get(WalletPayment::class);
        $balance = $this->balance();
        $payment = $walletPayment->open($order, true);
        $this->reseller->setDiscountBp(0);
        $this->em->flush();

        self::assertFalse($walletPayment->canPay($order), 'never offered as "pay from wallet"');
        self::assertSame([], self::getContainer()->get(PaymentStarter::class)->methodsFor($order), 'no gateway for a reseller order');
        try {
            $walletPayment->pay($order);
            self::fail('a reseller order was paid outside ResellerCheckout');
        } catch (WalletException $e) {
            self::assertSame('order_not_payable', $e->error);
        }
        $result = $walletPayment->pay($order, true);
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);
        self::assertSame($payment->getId(), $result->payment?->getId(), 'the opened payment, not a new one');
        self::assertSame(105000, $result->payment->getPayableAmount());
        self::assertSame($balance - 105000, $this->balance(), 'debited once, at the snapshot');
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    private function onlyOrder(): Order
    {
        $orders = $this->em->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);
        $this->em->refresh($orders[0]);

        return $orders[0];
    }

    private function assertRefused(string $error): void
    {
        try {
            $this->buy();
            self::fail('bought while it should be refused: '.$error);
        } catch (ResellerException|WalletException $e) {
            self::assertSame($error, $e->error);
        }
    }
}
