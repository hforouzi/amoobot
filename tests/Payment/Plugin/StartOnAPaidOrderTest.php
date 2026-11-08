<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Order;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Another payment settles the order after the caller checked it and before start() locks it: claimPayment() reads the
 * order again under its lock and starts nothing, so no second invoice is created. Each caller answers as for an order
 * it finds unpayable itself. InvoicingGatewayPlugin::$onLifetime runs in exactly that window.
 */
final class StartOnAPaidOrderTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ConstrainedGatewayFixture;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const NOT_PAYABLE_POPUP = 'سفارش نامعتبر است.';

    private StorePaymentMethod $invoiceMethod;
    /** The order was changed by $onLifetime, so start() itself ran. */
    private bool $changedMeanwhile = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->invoiceMethod = $this->constrainedMethod();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    private function setStatus(Order $order, string $status): void
    {
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [$status, $order->getId()]);
    }

    /** The order becomes $status between the caller's check and the claim. */
    private function settledMeanwhile(Order $order, string $status = OrderStatus::PAID): void
    {
        InvoicingGatewayPlugin::$onLifetime = function () use ($order, $status): void {
            $this->setStatus($order, $status);
            $this->changedMeanwhile = true;
        };
    }

    private function paymentRows(Order $order): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$order->getId()]);
    }

    /**
     * @return iterable<string, array{?int, string}>
     */
    public static function lifetimesAndStatuses(): iterable
    {
        yield 'a lifetime gateway, paid' => [3600, OrderStatus::PAID];
        yield 'a null-lifetime gateway, paid' => [null, OrderStatus::PAID];
        yield 'a lifetime gateway, cancelled' => [3600, OrderStatus::CANCELLED];
        yield 'a null-lifetime gateway, cancelled' => [null, OrderStatus::CANCELLED];
    }

    #[DataProvider('lifetimesAndStatuses')]
    public function testNoPaymentAndNoGatewayCallForAnOrderNoLongerAwaitingPayment(?int $lifetime, string $status): void
    {
        InvoicingGatewayPlugin::$lifetime = $lifetime;
        $order = $this->createOrder($this->user, $this->plan);
        $this->settledMeanwhile($order, $status);

        $start = self::getContainer()->get(PaymentStarter::class)->start($order, $this->invoiceMethod);

        self::assertSame(PaymentStart::KIND_ORDER_NOT_PAYABLE, $start->kind);
        self::assertFalse($start->success);
        self::assertNull($start->payment);
        self::assertFalse($start->isReady());
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
        self::assertSame(0, $this->paymentRows($order));
        self::assertSame($status, $this->row('`order`', (int) $order->getId())['status']);
    }

    /** The open row is left as it is: no reservation, no takeover, no superseding. */
    public function testTheOpenPaymentIsLeftAlone(): void
    {
        $order = $this->createOrder($this->user, $this->plan);
        $open = $this->createPayment($order, $this->invoiceMethod->getGateway(), PaymentStatus::PENDING);
        $open->setStorePaymentMethod($this->invoiceMethod)->setGatewayTransactionId('inv-old')->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
        $before = $this->row('payment', (int) $open->getId());
        $this->settledMeanwhile($order);

        $start = self::getContainer()->get(PaymentStarter::class)->start($order, $this->invoiceMethod);

        self::assertSame(PaymentStart::KIND_ORDER_NOT_PAYABLE, $start->kind);
        self::assertSame($before, $this->row('payment', (int) $open->getId()));
        self::assertSame(1, $this->paymentRows($order));
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
    }

    /** A core gateway (no constraints interface): the caller's stale copy says waiting, the locked row says paid. */
    public function testACoreGatewayIsNotAskedEither(): void
    {
        $order = $this->createOrder($this->user, $this->plan);
        $this->setStatus($order, OrderStatus::PAID);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $order->getStatus(), 'the caller read it before the settlement');
        $this->http->reset();

        $start = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[PaymentGatewayType::ZIBAL]);

        self::assertSame(PaymentStart::KIND_ORDER_NOT_PAYABLE, $start->kind);
        self::assertSame(OrderStatus::PAID, $order->getStatus(), 'refreshed under the lock');
        self::assertSame([], $this->http->requests('gateway.zibal.ir'));
        self::assertSame(0, $this->paymentRows($order));
    }

    public function testAnOrderStillAwaitingPaymentStartsAsBefore(): void
    {
        $order = $this->createOrder($this->user, $this->plan);

        $start = self::getContainer()->get(PaymentStarter::class)->start($order, $this->invoiceMethod);

        self::assertTrue($start->isReady());
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
    }

    public function testTheMiniAppAnswersOrderNotPayable(): void
    {
        $order = $this->createOrder($this->user, $this->plan);
        $session = $this->authenticate(self::CUSTOMER);
        $this->settledMeanwhile($order);
        $key = self::newKey();

        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->invoiceMethod->getId()], $key);

        self::assertTrue($this->changedMeanwhile, 'start() ran, after the caller\'s own check');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('order_not_payable', $answer['error'] ?? null);
        self::assertSame('این سفارش دیگر قابل پرداخت نیست.', $answer['message'] ?? null);
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
        self::assertSame(0, $this->paymentRows($order));
        self::assertSame(0, $this->rows('checkout_request'), 'a refusal is not stored: the key is free again');
    }

    public function testTheBotAnswersAsForAnInvalidOrder(): void
    {
        $order = $this->createOrder($this->user, $this->plan);
        $this->settledMeanwhile($order);
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->invoiceMethod->getId());

        $this->assertBotRefused($order);
    }

    public function testTheBotRenewalAnswersAsForAnInvalidOrder(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::RENEWAL, null, $this->createActiveService($this->user, $this->inbound));
        $this->settledMeanwhile($order);
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->invoiceMethod->getId());

        $this->assertBotRefused($order);
    }

    private function assertBotRefused(Order $order): void
    {
        self::assertTrue($this->changedMeanwhile, 'start() ran, after the caller\'s own check');
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
        $alerts = $this->http->requests('answerCallbackQuery');
        self::assertCount(1, $alerts);
        self::assertSame(self::NOT_PAYABLE_POPUP, $this->requestBody($alerts[0])['text'] ?? null);
        self::assertSame([], $this->http->requests('sendMessage'), 'no payment link');
        self::assertSame(0, $this->paymentRows($order));
    }
}
