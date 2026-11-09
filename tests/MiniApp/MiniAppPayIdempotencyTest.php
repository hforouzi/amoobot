<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Order;
use App\Entity\StorePaymentMethod;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;

/**
 * POST /app/api/orders/{id}/pay runs once per Idempotency-Key with claimOnce(): no outer transaction, so the order
 * lock PaymentStarter takes is released before the gateway is called. A repeat with the key gets the first answer, a
 * failed call frees the key, a call still running answers idempotency_in_progress.
 */
final class MiniAppPayIdempotencyTest extends IntegrationTestCase
{
    use ConstrainedGatewayFixture;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private StorePaymentMethod $invoiceMethod;
    private Order $order;
    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->invoiceMethod = $this->constrainedMethod();
        $this->order = $this->createOrder($this->user, $this->plan);
        $this->startMiniAppClient();
        $this->session = $this->authenticate(self::CUSTOMER);
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function pay(string $key): array
    {
        return $this->appPostJson('/app/api/orders/'.$this->order->getId().'/pay', $this->session, ['methodId' => $this->invoiceMethod->getId()], $key);
    }

    private function storedAnswer(string $key): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT result FROM checkout_request WHERE idempotency_key = ?', [$key]);
    }

    public function testTheGatewayIsCalledWithNoTransactionOpen(): void
    {
        $levels = [];
        InvoicingGatewayPlugin::$onCall = function () use (&$levels): void {
            $levels[] = $this->em->getConnection()->getTransactionNestingLevel();
        };

        $this->pay(self::newKey());

        self::assertSame(200, $this->httpStatus());
        self::assertSame([0], $levels, 'no lock (order, payment or Idempotency-Key row) is held during createPayment()');
    }

    public function testTheSameKeyGetsTheSameAnswerAndOneInvoice(): void
    {
        $key = self::newKey();

        $first = $this->pay($key);
        self::assertSame(200, $this->httpStatus());
        $second = $this->pay($key);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($first, $second);
        self::assertSame('/app/view/payments/', substr((string) $first['redirect'], 0, 19));
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
        self::assertSame(1, $this->rows('payment'));
        self::assertIsString($this->storedAnswer($key));
    }

    /** As before: the gateway's refusal is the stored answer of its key; the app drops the key, a new one works. */
    public function testARefusalIsStoredForItsKeyAndANewKeyWorks(): void
    {
        InvoicingGatewayPlugin::$answer = 'fail';
        $key = self::newKey();

        $first = $this->pay($key);
        self::assertSame(502, $this->httpStatus());
        self::assertSame('payment_start_failed', $first['error'] ?? null);
        self::assertSame($first, $this->pay($key));
        self::assertSame(502, $this->httpStatus());
        self::assertSame(1, InvoicingGatewayPlugin::$calls, 'the repeat is answered from the key');

        InvoicingGatewayPlugin::$answer = null;
        $this->pay(self::newKey());

        self::assertSame(200, $this->httpStatus());
        self::assertSame(2, InvoicingGatewayPlugin::$calls);
        self::assertSame(1, $this->rows('payment'), 'the refused row is the one started again');
    }

    /** A call that blows up frees its key: the same key works on the next tap, on the row the failed call claimed. */
    public function testAFailedCallFreesItsKey(): void
    {
        InvoicingGatewayPlugin::$answer = 'throw';
        $key = self::newKey();
        $this->client->catchExceptions(true);

        $this->pay($key);

        self::assertSame(500, $this->httpStatus());
        self::assertFalse($this->storedAnswer($key), 'no row for the key');
        self::assertSame(1, $this->rows('payment'), 'the claim committed on its own, as in the bot');

        InvoicingGatewayPlugin::$answer = null;
        $answer = $this->pay($key);

        self::assertSame(200, $this->httpStatus());
        self::assertIsString($answer['redirect'] ?? null);
        self::assertSame(2, InvoicingGatewayPlugin::$calls);
        self::assertSame(1, $this->rows('payment'));
    }

    public function testAKeyStillRunningIsRefusedWithAMessage(): void
    {
        $key = self::newKey();
        $this->em->getConnection()->insert('checkout_request', [
            'user_id' => $this->user->getId(),
            'idempotency_key' => $key,
            'scope' => 'pay:'.$this->order->getId(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $answer = $this->pay($key);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('idempotency_in_progress', $answer['error'] ?? null);
        self::assertSame('این پرداخت در حال آماده‌سازی است. چند ثانیه صبر کنید و سفارش را دوباره باز کنید.', $answer['message'] ?? null);
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
    }

    public function testAKeyOfAnotherOrderIsRefused(): void
    {
        $key = self::newKey();
        $other = $this->createOrder($this->user, $this->plan);
        $this->appPostJson('/app/api/orders/'.$other->getId().'/pay', $this->session, ['methodId' => $this->invoiceMethod->getId()], $key);
        self::assertSame(200, $this->httpStatus());

        $answer = $this->pay($key);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('idempotency_conflict', $answer['error'] ?? null);
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
    }
}
