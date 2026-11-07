<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\InvoiceRowFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * One invoice per row: PaymentStarter::claimPayment() for a gateway with an invoice lifetime, the reservation that covers
 * its createPayment(), and every gateway without one, which keeps reusing the open row.
 */
final class InvoiceRowClaimTest extends IntegrationTestCase
{
    use InvoiceRowFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    private static function assertSuperseded(array $row): void
    {
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED, $row['confirmation_source']);
        self::assertSame(Payment::NOTE_GATEWAY_INVOICE_SUPERSEDED, $row['admin_note']);
        self::assertNull($row['failed_at']);
        self::assertNotNull($row['gateway_transaction_id'], 'the references stay: the old invoice can still be paid');
        self::assertNotNull($row['authority']);
    }

    public function testAFirstStartReservesThenStoresTheInvoice(): void
    {
        $start = $this->start();

        self::assertTrue($start->isReady());
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
        $reservation = InvoicingGatewayPlugin::$expiryAtCall?->getTimestamp() - time();
        self::assertGreaterThan(60, $reservation, 'the row was reserved before the provider was called');
        self::assertLessThanOrEqual(90, $reservation);
        self::assertGreaterThan(3500, $this->secondsLeft($start->payment ?? throw new \LogicException()));
    }

    public function testTheCoreSetsTheExpiryWhenThePluginLeavesTheReservation(): void
    {
        InvoicingGatewayPlugin::$stamp = false;

        $payment = $this->start()->payment ?? throw new \LogicException();

        self::assertGreaterThan(3500, $this->secondsLeft($payment));
        self::assertLessThanOrEqual(3600, $this->secondsLeft($payment));
    }

    public function testALiveInvoiceOfTheSameGatewayIsReusedWithoutAProviderCall(): void
    {
        $first = $this->start();
        $expiresAt = $this->paymentRow($first->payment ?? throw new \LogicException())['expires_at'];
        $second = $this->start();

        self::assertTrue($second->isReady());
        self::assertSame($first->payment->getId(), $second->payment?->getId());
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
        self::assertSame(1, $this->paymentRows());
        self::assertSame($expiresAt, $this->paymentRow($first->payment)['expires_at'], 'a reuse writes no reservation');
    }

    public function testSwitchingGatewayClosesTheLiveInvoiceAndCreatesANewRow(): void
    {
        $first = $this->start()->payment ?? throw new \LogicException();

        $second = $this->start($this->otherInvoiceMethod());

        self::assertTrue($second->isReady());
        self::assertNotSame($first->getId(), $second->payment?->getId());
        self::assertSuperseded($this->paymentRow($first));
        self::assertSame(PaymentStatus::PENDING, $this->paymentRow($second->payment ?? throw new \LogicException())['status']);
        self::assertNotSame($this->paymentRow($first)['gateway_transaction_id'], $this->paymentRow($second->payment)['gateway_transaction_id']);
        self::assertSame(2, InvoicingGatewayPlugin::$calls);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $this->order->getId())['status'], 'the order stays open');
    }

    public function testAnExpiredInvoiceIsClosedAndANewRowCreated(): void
    {
        $old = $this->invoiceRow('old', '-1 minute');

        $start = $this->start();

        self::assertTrue($start->isReady());
        self::assertNotSame($old->getId(), $start->payment?->getId());
        self::assertSuperseded($this->paymentRow($old));
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
    }

    public function testAChangedAmountGetsANewInvoice(): void
    {
        $old = $this->invoiceRow('old', '+30 minutes');
        $this->order->setAmount(self::PRICE - 20000);
        $this->em->flush();

        $start = $this->start();

        self::assertNotSame($old->getId(), $start->payment?->getId());
        self::assertSuperseded($this->paymentRow($old));
        self::assertSame(self::PRICE - 20000, $start->payment?->getPayableAmount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'the same gateway' => ['same'];
        yield 'another gateway with a lifetime' => ['other'];
        yield 'card to card, with no lifetime' => ['manual'];
    }

    /** A start is creating this row's invoice: whatever the customer chose now, that invoice lands on this row. */
    #[DataProvider('targets')]
    public function testARunningReservationRefusesAnotherStart(string $target): void
    {
        $reserved = $this->invoiceRow(null, '+60 seconds');
        $before = $this->paymentRow($reserved);
        $method = match ($target) {
            'other' => $this->otherInvoiceMethod(),
            'manual' => $this->storeMethod($this->createGateway()),
            default => $this->invoiceMethod,
        };

        $start = $this->start($method);

        self::assertFalse($start->success);
        self::assertFalse($start->isReady(), 'no caller may treat it as a started payment');
        self::assertSame(PaymentStart::KIND_ONLINE, $start->kind);
        self::assertSame(PaymentStarter::INVOICE_IN_PROGRESS_MESSAGE, $start->message);
        self::assertSame($reserved->getId(), $start->payment?->getId());
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
        self::assertSame(1, $this->paymentRows());
        self::assertEquals($before, $this->paymentRow($reserved));
    }

    public function testTheLockedRowDecidesNotAManagedCopyOfIt(): void
    {
        $payment = $this->invoiceRow('old', '+30 minutes');
        $this->em->getConnection()->executeStatement('UPDATE payment SET gateway_transaction_id = NULL, expires_at = ? WHERE id = ?', [(new \DateTimeImmutable('+60 seconds'))->format('Y-m-d H:i:s'), $payment->getId()]);

        $start = $this->start();

        self::assertSame(PaymentStarter::INVOICE_IN_PROGRESS_MESSAGE, $start->message);
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
    }

    public function testAStaleReservationIsReused(): void
    {
        $stale = $this->invoiceRow(null, '-1 second');

        $start = $this->start();

        self::assertTrue($start->isReady());
        self::assertSame($stale->getId(), $start->payment?->getId());
        self::assertSame(1, $this->paymentRows());
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
        self::assertGreaterThan(3500, $this->secondsLeft($stale));
    }

    public function testAStaleReservationReusedWithoutALifetimeIsNoInvoiceAnyMore(): void
    {
        $stale = $this->invoiceRow(null, '-1 second');

        $start = $this->start($this->storeMethod($this->createGateway()));

        self::assertSame($stale->getId(), $start->payment?->getId());
        self::assertSame(PaymentStart::KIND_MANUAL_CARD, $start->kind);
        self::assertNull($this->paymentRow($stale)['expires_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function answersWithoutAnInvoice(): iterable
    {
        yield 'a refusal' => ['fail'];
        yield 'a success with no reference' => ['no_reference'];
    }

    #[DataProvider('answersWithoutAnInvoice')]
    public function testAnAnswerWithoutAnInvoiceClearsTheReservation(string $answer): void
    {
        InvoicingGatewayPlugin::$answer = $answer;

        $first = $this->start();

        self::assertNull($this->paymentRow($first->payment ?? throw new \LogicException())['expires_at']);

        InvoicingGatewayPlugin::$answer = null;
        $second = $this->start();

        self::assertTrue($second->isReady());
        self::assertSame($first->payment->getId(), $second->payment?->getId(), 'no invoice on it, so the row is reused');
        self::assertSame(2, InvoicingGatewayPlugin::$calls);
    }

    public function testAProviderExceptionClearsTheReservation(): void
    {
        InvoicingGatewayPlugin::$answer = 'throw';

        try {
            $this->start();
            self::fail('the exception reaches the caller, as ever');
        } catch (\RuntimeException $e) {
            self::assertSame('The provider blew up.', $e->getMessage());
        }

        self::assertSame(1, $this->paymentRows());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT expires_at FROM payment WHERE order_id = ?', [$this->order->getId()]));
    }

    public function testALifetimeThatThrowsStartsNothing(): void
    {
        InvoicingGatewayPlugin::$answer = 'lifetime_throws';

        try {
            $this->start();
            self::fail('no lifetime known, so nothing may be claimed');
        } catch (\RuntimeException $e) {
            self::assertSame('The lifetime blew up.', $e->getMessage());
        }

        self::assertSame(0, $this->paymentRows());
        self::assertSame(0, InvoicingGatewayPlugin::$calls);
    }

    public function testAGatewayThatCannotLoadFailsAfterTheClaimAsEver(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE plugin SET status = ? WHERE code = ?', [Plugin::STATUS_DISABLED, self::PLUGIN_CODE]);
        $this->em->clear();
        $this->order = $this->em->find(Order::class, $this->order->getId()) ?? throw new \LogicException();
        $this->invoiceMethod = $this->em->find(StorePaymentMethod::class, $this->invoiceMethod->getId()) ?? throw new \LogicException();

        try {
            $this->start();
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('plugin_disabled', $e->getMessage());
        }

        self::assertSame(1, $this->paymentRows());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT expires_at FROM payment WHERE order_id = ?', [$this->order->getId()]));
    }

    public function testALiveInvoiceIsClosedWhenSwitchingToAGatewayWithoutALifetime(): void
    {
        $invoice = $this->start()->payment ?? throw new \LogicException();

        $start = $this->start($this->storeMethod($this->createGateway()));

        self::assertSame(PaymentStart::KIND_MANUAL_CARD, $start->kind);
        self::assertNotSame($invoice->getId(), $start->payment?->getId());
        self::assertSuperseded($this->paymentRow($invoice));
        self::assertNull($this->paymentRow($start->payment ?? throw new \LogicException())['expires_at']);
    }

    public function testAnotherGatewaysOpenReferenceIsNotOverwritten(): void
    {
        $zibal = $this->createPayment($this->order, $this->createGateway(PaymentGatewayType::ZIBAL), PaymentStatus::PENDING);
        $zibal->setGatewayTransactionId('777')->setAuthority('777');
        $this->em->flush();

        $start = $this->start();

        self::assertNotSame($zibal->getId(), $start->payment?->getId());
        self::assertSuperseded($this->paymentRow($zibal));
        self::assertSame('777', $this->paymentRow($zibal)['gateway_transaction_id']);
    }

    public function testAnOpenRowWithoutAnyReferenceIsTakenOverAsEver(): void
    {
        $manual = $this->createPayment($this->order, $this->createGateway(), PaymentStatus::PENDING);

        $start = $this->start();

        self::assertSame($manual->getId(), $start->payment?->getId());
        self::assertSame(self::PLUGIN_CODE, $this->paymentRow($manual)['gateway_type']);
        self::assertSame(1, $this->paymentRows());
        self::assertGreaterThan(3500, $this->secondsLeft($manual));
    }

    public function testASubmittedReceiptStaysForTheAdminBesideTheNewInvoice(): void
    {
        $receipt = $this->createPayment($this->order, $this->createGateway(), PaymentStatus::SUBMITTED);
        $before = $this->paymentRow($receipt);

        $start = $this->start();

        self::assertTrue($start->isReady());
        self::assertNotSame($receipt->getId(), $start->payment?->getId());
        self::assertEquals($before, $this->paymentRow($receipt));
    }

    public function testNoLifetimeReusesTheRowAndWritesNoExpiry(): void
    {
        InvoicingGatewayPlugin::$lifetime = null;

        $first = $this->start();
        $second = $this->start();

        self::assertSame($first->payment?->getId(), $second->payment?->getId());
        self::assertSame(1, $this->paymentRows());
        self::assertSame(2, InvoicingGatewayPlugin::$calls, 'as today: every start asks the gateway');
        self::assertNull(InvoicingGatewayPlugin::$expiryAtCall);
        self::assertNull($this->paymentRow($first->payment ?? throw new \LogicException())['expires_at']);
    }

    public function testACoreGatewayKeepsItsRowOnASwitchAndWritesNoExpiry(): void
    {
        $first = $this->start($this->storeMethod($this->createGateway()));
        $second = $this->start($this->storeMethod($this->createGateway()));

        self::assertSame($first->payment?->getId(), $second->payment?->getId());
        self::assertSame(1, $this->paymentRows());
        self::assertNull($this->paymentRow($first->payment ?? throw new \LogicException())['expires_at']);
        self::assertNull($this->paymentRow($first->payment)['confirmation_source']);
    }
}
