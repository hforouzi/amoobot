<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Admin\UI\Dashboard\DashboardOverview;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminActor;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Review finding I2: money that went into a wallet and was then spent from it was counted twice (the top-up payment
 * and the wallet payment are both CONFIRMED). Revenue and a customer's "paid in total" are the money received: every
 * confirmed payment except those of method "wallet" (a wallet payment moves no new money). Wallet top-ups, which are
 * part of that money, are shown on their own line. A shop without wallet payments sees exactly the old numbers.
 */
final class WalletRevenueReportingTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    private function confirmed(Order $order, int $amount, ?string $method = null): Payment
    {
        $payment = $this->createPayment($order, null, PaymentStatus::CONFIRMED)->setAmount($amount)->setPayableAmount($amount)->setConfirmedAt(new \DateTimeImmutable());
        if (null !== $method) {
            $payment->setMethod($method)->setGatewayType($method);
        }
        $order->setStatus(OrderStatus::PROVISIONED);
        $this->em->flush();

        return $payment;
    }

    private function topUpOrder(int $amount): Order
    {
        $order = (new Order())->setUser($this->buyer)->setType(OrderType::WALLET_TOPUP)->setAmount($amount)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    /** 150,000 topped up by gateway and spent from the wallet; 100,000 paid directly by gateway: 250,000 received. */
    private function walletAndDirectSales(): void
    {
        $plan = $this->payment->getOrder()->requirePlan();
        $this->confirmed($this->topUpOrder(150000), 150000);
        $this->confirmed($this->createOrder($this->buyer, $plan), 150000, Payment::METHOD_WALLET);
        $this->confirmed($this->createOrder($this->buyer, $plan), 100000);
    }

    /**
     * @return array{orders: int|null, payments: int|null, revenue: int|null, walletTopUps: int|null, customers: int|null}
     */
    private function today(): array
    {
        $today = self::getContainer()->get(DashboardOverview::class)->for(AdminActor::fromAdminUser($this->adminUser))['today'];
        self::assertIsArray($today);

        return $today;
    }

    public function testTheDashboardCountsMoneyReceivedOnceAndShowsTopUpsSeparately(): void
    {
        $this->walletAndDirectSales();

        $today = $this->today();

        self::assertSame(250000, $today['revenue'], 'gateway money only: the wallet payment moved no new money');
        self::assertSame(150000, $today['walletTopUps']);
        self::assertSame(3, $today['payments']);
    }

    /** Second review, minor 4: a top-up underpaid within tolerance counts what was credited, not the ordered amount. */
    public function testAnUnderpaidTopUpCountsWhatWasCredited(): void
    {
        $topUp = $this->confirmed($this->topUpOrder(50000), 50000);
        // What the settlement credits for 99.5% received (TopUpCredit), under the top-up's ledger key.
        self::getContainer()->get(WalletService::class)->credit($this->buyer, 49750, WalletTransactionType::TOPUP, WalletKeys::topUp((int) $topUp->getId()), WalletActor::system());
        $this->confirmed($this->createOrder($this->buyer, $this->payment->getOrder()->requirePlan()), 100000);

        $today = $this->today();

        self::assertSame(149750, $today['revenue']);
        self::assertSame(49750, $today['walletTopUps']);
        self::assertSame(2, $today['payments']);
    }

    public function testWithoutWalletPaymentsTheRevenueIsUnchanged(): void
    {
        $this->confirmed($this->createOrder($this->buyer, $this->payment->getOrder()->requirePlan()), 100000);

        $today = $this->today();

        self::assertSame(100000, $today['revenue']);
        self::assertSame(0, $today['walletTopUps']);
    }

    public function testTheCustomerPageShowsWhatTheCustomerPaidOnce(): void
    {
        $this->walletAndDirectSales();
        $session = $this->authenticate(self::ADMIN);

        $page = new Crawler($this->appGet('/app/admin/customers/'.$this->buyer->getId(), $session));

        self::assertSame(200, $this->httpStatus());
        self::assertSame('250000', $page->filter('[data-payments-summary] [data-paid-total]')->attr('data-paid-total'));
        self::assertSame('150000', $page->filter('[data-payments-summary] [data-wallet-topups]')->attr('data-wallet-topups'));
    }

    public function testTheMiniAppHomeAndTheWebDashboardShowTheTopUpLine(): void
    {
        $this->walletAndDirectSales();

        $home = new Crawler($this->appGet('/app/admin', $this->authenticate(self::ADMIN)));
        self::assertSame(200, $this->httpStatus());
        self::assertSame('250000', $home->filter('[data-card="today"] [data-revenue]')->attr('data-revenue'));
        self::assertSame('150000', $home->filter('[data-card="today"] [data-wallet-topups]')->attr('data-wallet-topups'));

        $this->client->loginUser($this->adminUser, 'main');
        $dashboard = $this->client->request('GET', '/admin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('250,000', $dashboard->filter('[data-tab="revenue"]')->text());
        self::assertStringContainsString('150,000', $dashboard->filter('[data-tab="wallet_topups"]')->text());
    }
}
