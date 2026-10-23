<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\IncompleteOrderExpiryService;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Review finding B2: WalletPayment::pay() is open() (debit, commit) then settle(). A customer cancel or the expiry job
 * landing between the two gave the debit back; settle() then took the wallet payment for money "paid after cancel",
 * parked it in review with a reason saying the gateway reported it paid, and an admin approval provisioned the order
 * for free.
 *
 * A wallet payment is never parked as a late payment, and it is confirmed (by its own settlement, an admin approval
 * or a retry) only while its debit exists and has not been given back.
 */
final class WalletLatePaymentTest extends IntegrationTestCase
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

    /** @phpstan-impure reads the database */
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

    /** What WalletPayment::pay() hands to settle() after open(): the debit as the verification. */
    private function walletVerification(int $paymentId): VerifiedPayment
    {
        $debit = self::getContainer()->get(WalletService::class)->findTransaction(WalletKeys::purchase($paymentId)) ?? throw new \LogicException('no debit');

        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'wallet:'.$debit->getId(), amount: (string) -$debit->getAmount(), currency: 'IRT'));
    }

    private function newOrder(): Order
    {
        return $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function interruptions(): iterable
    {
        yield 'customer cancel' => ['cancel'];
        yield 'order expiry' => ['expiry'];
    }

    #[DataProvider('interruptions')]
    public function testAWalletPaymentInterruptedBeforeItsSettlementIsNeverParkedNorProvisioned(string $interruption): void
    {
        $order = $this->newOrder();
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $verification = $this->walletVerification((int) $payment->getId());

        $this->interrupt($interruption, $order);
        self::assertSame(self::FUNDED, $this->balance(), 'the debit was given back');

        // The rest of pay(): its settlement finds the payment rejected and leaves it so.
        $late = $this->settlement()->settle($this->freshPayment((int) $payment->getId()), $verification, WalletPayment::SOURCE);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $late->outcome, $late->message);
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertNull($row['review_reason']);

        $approved = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');
        self::assertNotSame(SettlementOutcome::CONFIRMED, $approved->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(self::FUNDED, $this->balance());
    }

    /** A wallet payment already parked by the old code ("paid after cancel", debit given back) cannot be approved. */
    public function testAnAdminCannotApproveAWalletPaymentWhoseDebitWasGivenBack(): void
    {
        $order = $this->newOrder();
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $this->settlement()->cancelByCustomer($this->fresh($order));
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'needs_review', review_reason = 'paid after cancel: legacy' WHERE id = ?", [$payment->getId()]);

        $approved = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::WALLET_DEBIT_MISSING, $approved->outcome);
        self::assertStringContainsString('given back', $approved->message);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status'], 'nothing changed');
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(self::FUNDED, $this->balance());

        // Rejecting it is what the admin should do: nothing more is given back.
        $this->settlement()->reject($this->freshPayment((int) $payment->getId()), 'no money', 1, null, 'test_admin');
        self::assertSame(self::FUNDED, $this->balance());
    }

    public function testAWalletPaymentWithoutAnyDebitIsNeverConfirmed(): void
    {
        $order = $this->newOrder();
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'wallet', gateway_type = 'wallet', payable_amount = ? WHERE id = ?", [self::PRICE, $payment->getId()]);

        $approved = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');
        $settled = $this->settlement()->settle($this->freshPayment((int) $payment->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'wallet:0', amount: (string) self::PRICE, currency: 'IRT')), WalletPayment::SOURCE);

        self::assertSame(SettlementOutcome::WALLET_DEBIT_MISSING, $approved->outcome);
        self::assertStringContainsString('no wallet debit', $approved->message);
        self::assertSame(SettlementOutcome::WALLET_DEBIT_MISSING, $settled->outcome);
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testARetryOfAFailedWalletPaymentWhoseDebitWasGivenBackIsRefused(): void
    {
        $order = $this->newOrder();
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        self::getContainer()->get(WalletService::class)->releasePurchase((int) $payment->getId(), WalletActor::system(), 'test');
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'provisioning_failed' WHERE id = ?", [$payment->getId()]);

        $retry = $this->settlement()->retryProvisioning($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::WALLET_DEBIT_MISSING, $retry->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testAnIntactWalletPaymentParkedForReviewIsStillApprovable(): void
    {
        $order = $this->newOrder();
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'needs_review', review_reason = 'checked by hand' WHERE id = ?", [$payment->getId()]);

        $approved = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::CONFIRMED, $approved->outcome, $approved->message);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    // --- a real race: the customer pays from the wallet and cancels at the same time (two processes) ----------

    /**
     * @return iterable<string, array{int}>
     */
    public static function cancelDelays(): iterable
    {
        foreach ([0, 50, 150, 300, 600] as $delayMs) {
            yield 'cancel after '.$delayMs.'ms' => [$delayMs];
        }
    }

    #[Group('subprocess')]
    #[DataProvider('cancelDelays')]
    public function testPayAndCancelRacingEndInExactlyOneConsistentState(int $cancelDelayMs): void
    {
        $order = $this->newOrder();
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $env = ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '300'];
        $pay = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-pay.php', (string) $order->getId()], dirname(__DIR__, 2), $env, null, 60);
        $cancel = new Process([$php, dirname(__DIR__).'/Support/bin/cancel-order.php', (string) $order->getId(), (string) $cancelDelayMs], dirname(__DIR__, 2), $env, null, 60);
        $pay->start();
        $cancel->start();
        $pay->wait();
        $cancel->wait();
        self::assertTrue($pay->isSuccessful(), $pay->getErrorOutput().$pay->getOutput());
        self::assertTrue($cancel->isSuccessful(), $cancel->getErrorOutput().$cancel->getOutput());

        $connection = $this->em->getConnection();
        $statuses = $connection->fetchFirstColumn('SELECT status FROM payment WHERE order_id = ?', [$order->getId()]);
        self::assertNotContains(PaymentStatus::NEEDS_REVIEW, $statuses, 'a wallet payment is never parked as a late payment');
        $orderStatus = $this->row('`order`', (int) $order->getId())['status'];
        if (OrderStatus::PROVISIONED === $orderStatus) {
            self::assertSame(self::FUNDED - self::PRICE, $this->balance());
            self::assertSame(1, $this->countServicesForOrder($order));
        } else {
            self::assertSame(OrderStatus::CANCELLED, $orderStatus, sprintf('pay=%s cancel=%s', $pay->getOutput(), $cancel->getOutput()));
            self::assertSame(self::FUNDED, $this->balance(), 'cancelled: the debit (if any) came back');
            self::assertSame(0, $this->countServicesForOrder($order));
            foreach ($statuses as $status) {
                self::assertSame(PaymentStatus::REJECTED, $status);
            }
        }
        self::assertSame($this->balance(), (int) $connection->fetchOne('SELECT SUM(amount) FROM wallet_transaction'));
    }

    private function interrupt(string $interruption, Order $order): void
    {
        if ('cancel' === $interruption) {
            self::assertSame(SettlementOutcome::REJECTED, $this->settlement()->cancelByCustomer($this->fresh($order))->outcome);

            return;
        }
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        self::getContainer()->get(IncompleteOrderExpiryService::class)->expire(false, 1);
    }
}
