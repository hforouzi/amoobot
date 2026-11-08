<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A Mini App /pay whose gateway takes its time, in real processes on the real database, while an admin approves the
 * order's card receipt: the approval locks the order too, and must not wait for the gateway (no lock is held during
 * createPayment()). Before claimOnce() the route ran start() in once()'s outer transaction, so the approval waited for
 * the whole provider call.
 */
#[Group('subprocess')]
final class MiniAppPayLockParallelTest extends IntegrationTestCase
{
    use ConstrainedGatewayFixture;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;
    use ParallelStart;
    use SubprocessAssertions;

    /** How long the provider takes to create the invoice. */
    private const PROVIDER_MS = 8000;
    /** When the approval starts, after the /pay. Its claim is done by then; the provider call is not. */
    private const APPROVE_AT = 2.0;
    /** The approval's time limit: far below the PROVIDER_MS - APPROVE_AT it would wait for a held order lock. */
    private const APPROVE_LIMIT_MS = 4000;

    private StorePaymentMethod $invoiceMethod;
    private string $callsFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->invoiceMethod = $this->constrainedMethod();
        $this->callsFile = sys_get_temp_dir().'/miniapp-pay-race-'.bin2hex(random_bytes(6)).'.log';
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        @unlink($this->callsFile);
        $this->removeStart();
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    /**
     * @param list<string> $arguments
     */
    private function helper(string $script, array $arguments): Process
    {
        $process = new Process(
            [(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/'.$script, ...$arguments],
            dirname(__DIR__, 2),
            ['APP_ENV' => 'test', 'TEST_INVOICE_CALLS_FILE' => $this->callsFile, 'TEST_INVOICE_DELAY_MS' => (string) self::PROVIDER_MS],
            null,
            240
        );
        $process->start();

        return $process;
    }

    /** An order with a card receipt waiting for an admin: open, so the customer may still choose a gateway. */
    private function orderWithReceipt(): Payment
    {
        $order = $this->createOrder($this->user, $this->plan);
        $receipt = $this->createPayment($order, $this->methods[PaymentGatewayType::MANUAL_CARD]->getGateway(), PaymentStatus::SUBMITTED);
        $receipt->setStorePaymentMethod($this->methods[PaymentGatewayType::MANUAL_CARD]);
        $this->em->flush();

        return $receipt;
    }

    public function testAnApprovalDoesNotWaitForTheGatewayOfAMiniAppPay(): void
    {
        $receipt = $this->orderWithReceipt();
        $order = $receipt->getOrder();
        $session = $this->authenticate(self::CUSTOMER);

        $pay = $this->helper('miniapp-pay.php', [(string) $order->getId(), (string) $this->invoiceMethod->getId(), $session['token'], $session['csrf'], self::newKey(), $this->startAt()]);
        $approve = $this->helper('invoice-race.php', ['approve', (string) $receipt->getId(), '0', $this->startAt(self::APPROVE_AT)]);
        $this->releaseStart([$pay, $approve]);
        $pay->wait();
        $approve->wait();
        [$payStatus, $payMs, $payAnswer] = explode(' ', self::assertSubprocessClean($pay, 'mini app pay'), 3);
        [$approved, $approveMs] = explode(' ', self::assertSubprocessClean($approve, 'approval'), 2);
        $this->em->clear();

        self::assertSame('approve:confirmed', $approved);
        self::assertLessThan(self::APPROVE_LIMIT_MS, (int) $approveMs, 'the approval waited for the provider call');
        self::assertGreaterThanOrEqual(self::PROVIDER_MS, (int) $payMs, 'the provider call ran the whole time');
        self::assertLessThan((int) $payMs, (int) $approveMs + (int) (self::APPROVE_AT * 1000), 'the approval ended during the provider call');

        // The /pay claimed before the approval (else it would have been refused): it gets its invoice, beside the receipt.
        self::assertSame('200', $payStatus, $payAnswer);
        $answer = json_decode($payAnswer, true);
        self::assertIsArray($answer);
        self::assertSame('online', $answer['kind'] ?? null);
        self::assertSame(1, count(file($this->callsFile, FILE_IGNORE_NEW_LINES) ?: []));

        self::assertNotSame(OrderStatus::WAITING_PAYMENT, $this->freshOrder($order)->getStatus(), 'the receipt paid the order');
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $receipt->getId())['status']);
        $invoice = $this->em->getConnection()->fetchAssociative('SELECT * FROM payment WHERE order_id = ? AND id <> ?', [$order->getId(), $receipt->getId()]);
        self::assertIsArray($invoice);
        self::assertSame('/app/view/payments/'.$invoice['id'], $answer['redirect'] ?? null);
        self::assertSame(PaymentStatus::PENDING, $invoice['status'], 'unpaid: money paid on it later goes to review (parkLatePayment)');
        self::assertNotNull($invoice['gateway_transaction_id']);
    }

    private function freshOrder(Order $order): Order
    {
        return $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException('no order');
    }
}
