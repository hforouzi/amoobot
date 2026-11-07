<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Payment;
use App\Entity\Setting;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentDisplayState;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\InvoiceRowFixture;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * expireUnpaid(), and the money that comes after a payment was closed unpaid: every way of closing one keeps
 * acceptsGatewayVerification() true, so a later "paid" reaches settle() and waits in review. Never ignored, never confirmed.
 */
final class InvoiceExpiryTest extends IntegrationTestCase
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

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private function paid(Payment $payment): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: $payment->getGatewayTransactionId(), amount: (string) self::PRICE, currency: 'IRT'));
    }

    private function orderStatus(): string
    {
        return (string) $this->row('`order`', (int) $this->order->getId())['status'];
    }

    public function testAPendingPaymentBecomesRejectedAndTheOrderStaysOpen(): void
    {
        $payment = $this->invoiceRow('old', '-1 minute');
        $this->http->reset();

        $result = $this->settlement()->expireUnpaid($payment, 'test_timer');

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome);
        $row = $this->paymentRow($payment);
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_GATEWAY_INVOICE_EXPIRY, $row['confirmation_source']);
        self::assertSame(Payment::NOTE_GATEWAY_INVOICE_EXPIRY, $row['admin_note']);
        self::assertNull($row['failed_at']);
        self::assertSame('old', $row['gateway_transaction_id']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->orderStatus());
        self::assertSame([], $this->http->telegramTexts(), 'no message to the customer or the admins');
    }

    public function testTheCustomerCanStartAgainAfterExpiry(): void
    {
        $payment = $this->invoiceRow('old', '-1 minute');
        $this->settlement()->expireUnpaid($payment, 'test_timer');

        $start = $this->start();

        self::assertTrue($start->isReady());
        self::assertNotSame($payment->getId(), $start->payment?->getId());
        self::assertSame(Payment::SOURCE_GATEWAY_INVOICE_EXPIRY, $this->paymentRow($payment)['confirmation_source'], 'the expired row stays as it was');
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherStatuses(): iterable
    {
        foreach ([PaymentStatus::SUBMITTED, PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED, PaymentStatus::REJECTED] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('otherStatuses')]
    public function testOnlyAPendingPaymentChanges(string $status): void
    {
        $payment = $this->invoiceRow('old', '-1 minute')->setStatus($status)->setConfirmationSource('earlier');
        $this->em->flush();
        $before = $this->paymentRow($payment);

        $result = $this->settlement()->expireUnpaid($payment, 'test_timer');

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $result->outcome);
        self::assertEquals($before, $this->paymentRow($payment));
    }

    public function testAWalletPaymentGivesItsDebitBack(): void
    {
        $wallets = self::getContainer()->get(WalletService::class);
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true'));
        $this->em->flush();
        $user = $this->order->getUser();
        $wallets->credit($user, self::PRICE, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $payment = self::getContainer()->get(WalletPayment::class)->open($this->order);

        $this->settlement()->expireUnpaid($payment, 'test_timer');

        self::assertSame(PaymentStatus::REJECTED, $this->paymentRow($payment)['status']);
        self::assertSame(self::PRICE, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$user->getId()]));
    }

    /**
     * @return iterable<string, array{\Closure(self, Payment): mixed, string, string}>
     */
    public static function closings(): iterable
    {
        yield 'invoice expiry' => [static fn (self $test, Payment $payment) => $test->settlement()->expireUnpaid($payment, 'test_timer'), 'paid after invoice expiry', OrderStatus::WAITING_PAYMENT];
        yield 'superseded' => [static fn (self $test, Payment $payment) => $test->start($test->otherInvoiceMethod()), 'paid after invoice replaced', OrderStatus::WAITING_PAYMENT];
        yield 'customer cancel' => [static fn (self $test, Payment $payment) => $test->settlement()->cancelByCustomer($payment->getOrder()), 'paid after cancel', OrderStatus::CANCELLED];
    }

    /**
     * @param \Closure(self, Payment): mixed $close
     */
    #[DataProvider('closings')]
    public function testAPaidVerificationAfterClosingEndsInReview(\Closure $close, string $reason, string $orderStatus): void
    {
        $payment = $this->invoiceRow('old', '+30 minutes');
        $close($this, $payment);
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        self::assertSame(PaymentStatus::REJECTED, $payment->getStatus());
        self::assertTrue($payment->isRejectedUnpaid());
        self::assertTrue($payment->acceptsGatewayVerification());

        $result = $this->settlement()->settle($payment, $this->paid($payment), 'test_webhook');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        $row = $this->paymentRow($payment);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertStringStartsWith($reason, (string) $row['review_reason']);
        self::assertSame((string) self::PRICE, $row['verified_amount']);
        self::assertSame($orderStatus, $this->orderStatus());
        self::assertSame(0, $this->countServicesForOrder($this->order));
        self::assertTrue($result->payment?->isLatePaymentInReview());
    }

    public function testASecondPaidVerificationOfALatePaymentIsNotCountedAgain(): void
    {
        $payment = $this->invoiceRow('old', '-1 minute');
        $this->settlement()->expireUnpaid($payment, 'test_timer');
        $this->settlement()->settle($payment, $this->paid($payment), 'test_webhook');

        $again = $this->settlement()->settle($payment, $this->paid($payment), 'test_timer');

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $again->outcome);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->paymentRow($payment)['status']);
    }

    public function testWhatTheCustomerIsShown(): void
    {
        $rejected = static fn (string $source): Payment => (new Payment())->setStatus(PaymentStatus::REJECTED)->setConfirmationSource($source);

        $expired = $rejected(Payment::SOURCE_GATEWAY_INVOICE_EXPIRY);
        self::assertSame(PaymentDisplayState::EXPIRED, PaymentDisplayState::forCustomer($expired));
        self::assertSame(PaymentDisplayState::CANCELLED, PaymentDisplayState::forReturnPage($expired));

        $superseded = $rejected(Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED);
        self::assertSame(PaymentDisplayState::CANCELLED, PaymentDisplayState::forCustomer($superseded));
        self::assertSame(PaymentDisplayState::CANCELLED, PaymentDisplayState::forReturnPage($superseded));

        self::assertSame(PaymentDisplayState::FAILED, PaymentDisplayState::forCustomer($rejected('gateway_failure')));
    }
}
