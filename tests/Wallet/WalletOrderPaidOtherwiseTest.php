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
 * Second review, blocker 1: the customer started a gateway / card-to-card payment, then paid from the wallet and that
 * settlement never finished (the wallet payment stays pending with its debit), then completed the FIRST payment. The
 * order is paid by it; the wallet debit must come back in the same transaction, once, and nothing is left to reconcile.
 */
final class WalletOrderPaidOtherwiseTest extends IntegrationTestCase
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

    /**
     * An order with a started payment of $method and, after it, a wallet payment debited but never settled.
     *
     * @return array{Order, Payment, Payment} [order, first payment, wallet payment]
     */
    private function orderWithPendingWalletPaymentAfter(string $method): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $first = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[$method])->payment ?? throw new \LogicException();
        $wallet = self::getContainer()->get(WalletPayment::class)->open($this->fresh($order));
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());

        return [$order, $first, $wallet];
    }

    private function assertWalletPaymentReleased(Payment $wallet): void
    {
        $row = $this->row('payment', (int) $wallet->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_ORDER_PAID_OTHERWISE, $row['confirmation_source']);
        self::assertSame(self::FUNDED, $this->balance(), 'the wallet debit came back');
        self::assertNotNull(self::getContainer()->get(WalletService::class)->findTransaction(WalletKeys::purchaseReversal((int) $wallet->getId())));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testZibalCompletedAfterAnUnsettledWalletPaymentReleasesTheDebit(): void
    {
        [$order, $zibal, $wallet] = $this->orderWithPendingWalletPaymentAfter(PaymentGatewayType::ZIBAL);

        $result = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $this->assertWalletPaymentReleased($wallet);

        // A late retry of the wallet payment's own settlement changes nothing.
        $late = $this->settlement()->settle($this->freshPayment((int) $wallet->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'wallet:x', amount: (string) self::PRICE, currency: 'IRT')), WalletPayment::SOURCE);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $late->outcome);
        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
    }

    public function testCardToCardApprovedAfterAnUnsettledWalletPaymentReleasesTheDebit(): void
    {
        [$order, $card, $wallet] = $this->orderWithPendingWalletPaymentAfter(PaymentGatewayType::MANUAL_CARD);
        $receipts = self::getContainer()->get(ReceiptSubmission::class);
        $manual = $receipts->openManualPayment($this->user) ?? throw new \LogicException('no card payment to send a receipt for');
        self::assertSame($card->getId(), $manual->getId());
        $receipts->submitTrackingCode($manual, 'TRACK-1');

        $result = $this->settlement()->approveByAdmin($this->freshPayment((int) $card->getId()), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $this->assertWalletPaymentReleased($wallet);
    }

    /** The first payment is verified but the panel fails: the order is paid all the same, the debit comes back. */
    public function testAVerifiedPaymentWhoseProvisioningFailsAlsoReleasesTheDebit(): void
    {
        [, $zibal, $wallet] = $this->orderWithPendingWalletPaymentAfter(PaymentGatewayType::ZIBAL);
        $this->driver()->failNext(1);

        $result = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-2', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        $this->assertWalletPaymentReleased($wallet);
    }

    /** A payment parked for review is not "paid": the wallet payment keeps its debit until one of them is decided. */
    public function testAPaymentParkedForReviewLeavesTheWalletPaymentAlone(): void
    {
        [, $zibal, $wallet] = $this->orderWithPendingWalletPaymentAfter(PaymentGatewayType::ZIBAL);

        $result = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-3', amount: '1000', currency: 'IRT')), 'test_webhook');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $wallet->getId())['status']);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());
    }
}
