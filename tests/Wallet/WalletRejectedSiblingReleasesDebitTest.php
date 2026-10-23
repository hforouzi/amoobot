<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\ReceiptSubmission;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletTransactionType;

/**
 * Third review, blocker 2: a wallet payment was debited but its settlement never finished (pending, debit held), and a
 * sibling payment of the same order (a submitted card receipt, or a gateway payment in review) was rejected by an admin.
 * The order became failed and nothing could give the wallet debit back any more (paying again, cancel and expiry all
 * refuse a failed order). Now the order failing releases every held wallet debit of it in the same transaction.
 */
final class WalletRejectedSiblingReleasesDebitTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const PRICE = 150000;
    private const FUNDED = 200000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, self::FUNDED, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private function balance(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]);
    }

    private function fresh(Order $order): Order
    {
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $this->em->refresh($order);

        return $order;
    }

    private function freshPayment(int $id): Payment
    {
        $payment = $this->em->find(Payment::class, $id) ?? throw new \LogicException();
        $this->em->refresh($payment);

        return $payment;
    }

    /** @return array{Order, Payment, Payment} [order, first payment, wallet payment debited and never settled] */
    private function pendingWalletPaymentAfter(string $method): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $first = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[$method])->payment ?? throw new \LogicException();
        $wallet = self::getContainer()->get(WalletPayment::class)->open($this->fresh($order));
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());

        return [$order, $first, $wallet];
    }

    private function assertOrderFailedAndDebitBack(Order $order, Payment $wallet): void
    {
        self::assertSame(OrderStatus::FAILED, $this->row('`order`', (int) $order->getId())['status']);
        $row = $this->row('payment', (int) $wallet->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_ORDER_FAILED, $row['confirmation_source']);
        self::assertSame(self::FUNDED, $this->balance(), 'the wallet debit came back');
        self::assertNotNull(self::getContainer()->get(WalletService::class)->findTransaction(WalletKeys::purchaseReversal((int) $wallet->getId())));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testRejectingASubmittedCardReceiptReleasesThePendingWalletDebit(): void
    {
        [$order, $card, $wallet] = $this->pendingWalletPaymentAfter(PaymentGatewayType::MANUAL_CARD);
        $receipts = self::getContainer()->get(ReceiptSubmission::class);
        $receipts->submitTrackingCode($this->freshPayment((int) $card->getId()), 'TRACK-1');
        $before = count($this->http->telegramTexts());

        $result = $this->settlement()->reject($this->freshPayment((int) $card->getId()), 'fake receipt', 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        $this->assertOrderFailedAndDebitBack($order, $wallet);
        $texts = array_slice($this->http->telegramTexts(), $before);
        self::assertCount(1, array_filter($texts, static fn (string $t): bool => str_contains($t, '150,000') && str_contains($t, 'کیف پول')), 'the customer is told once that the amount came back');

        // Rejecting again, or rejecting / approving / retrying the released wallet payment, changes nothing.
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->reject($this->freshPayment((int) $card->getId()), 'again', 1, null, 'test_admin')->outcome);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->reject($this->freshPayment((int) $wallet->getId()), 'again', 1, null, 'test_admin')->outcome);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->approveByAdmin($this->freshPayment((int) $wallet->getId()), 1, null, 'test_admin')->outcome);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->settle($this->freshPayment((int) $wallet->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'wallet:x', amount: (string) self::PRICE, currency: 'IRT')), WalletPayment::SOURCE)->outcome);
        $this->assertOrderFailedAndDebitBack($order, $wallet);
    }

    public function testRejectingAGatewayPaymentInReviewReleasesThePendingWalletDebit(): void
    {
        [$order, $zibal, $wallet] = $this->pendingWalletPaymentAfter(PaymentGatewayType::ZIBAL);
        $parked = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: '1000', currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $parked->outcome);
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $wallet->getId())['status'], 'a payment in review does not pay the order');

        $result = $this->settlement()->reject($this->freshPayment((int) $zibal->getId()), 'underpaid, refunded', 1, null, 'test_admin', true);

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        $this->assertOrderFailedAndDebitBack($order, $wallet);
    }

    /** The order failing releases a wallet debit whatever its payment's status, provisioning_failed included. */
    public function testRejectingTheOrdersOtherPaymentReleasesAProvisioningFailedWalletDebitToo(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $card = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[PaymentGatewayType::MANUAL_CARD])->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $paid = self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $paid->outcome);

        $result = $this->settlement()->reject($this->freshPayment((int) $card->getId()), 'not needed', 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        $this->assertOrderFailedAndDebitBack($order, $paid->payment ?? throw new \LogicException());
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->retryProvisioning($this->freshPayment((int) $paid->payment->getId()), 1, null, 'test_admin')->outcome);
        self::assertSame(self::FUNDED, $this->balance());
    }

    /** A duplicate payment of a fulfilled order is rejected without touching the order or the wallet payment that paid it. */
    public function testRejectingADuplicateOfAWalletPaidOrderKeepsItsDebit(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $zibal = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        $paid = self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));
        self::assertSame(SettlementOutcome::CONFIRMED, $paid->outcome);
        $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-2', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');

        $result = $this->settlement()->reject($this->freshPayment((int) $zibal->getId()), 'duplicate', 1, null, 'test_admin', true);

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $paid->payment?->getId())['status']);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }
}
