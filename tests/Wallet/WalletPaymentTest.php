<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
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
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Part 3: paying a new service / renewal / add-traffic order from the wallet. The debit and a synthetic payment of
 * method "wallet" are written together; the payment is then confirmed by PaymentSettlementService exactly like a
 * gateway payment, so retry, review and provisioning rules are the existing ones. A rejected wallet payment gives its
 * debit back once.
 */
final class WalletPaymentTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const PRICE = 150000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
    }

    private function walletPayment(): WalletPayment
    {
        return self::getContainer()->get(WalletPayment::class);
    }

    private function wallets(): WalletService
    {
        return self::getContainer()->get(WalletService::class);
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private function fund(int $amount): void
    {
        $this->wallets()->credit($this->user, $amount, WalletTransactionType::TOPUP, 'seed:'.$amount.':'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function balance(): int
    {
        return (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]) ?: 0);
    }

    /**
     * @return list<array{type: string, amount: int}>
     */
    private function ledger(): array
    {
        return array_map(static fn (array $r): array => ['type' => (string) $r['type'], 'amount' => (int) $r['amount']], $this->em->getConnection()->fetchAllAssociative('SELECT type, amount FROM wallet_transaction ORDER BY id'));
    }

    private function order(string $type, int $amount = self::PRICE): Order
    {
        $target = OrderType::NEW_SERVICE === $type ? null : $this->createActiveService($this->user, $this->inbound);

        return $this->createOrder($this->user, $this->plan, $type, $amount, $target);
    }

    private function reload(Order $order): Order
    {
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $this->em->refresh($order);

        return $order;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function orderTypes(): iterable
    {
        yield 'new service' => [OrderType::NEW_SERVICE];
        yield 'renewal' => [OrderType::RENEWAL];
        yield 'add traffic' => [OrderType::ADD_TRAFFIC];
    }

    // --- availability ---------------------------------------------------------------------------

    public function testOfferedOnlyWhenTheBalanceAndCreditCoverTheTotal(): void
    {
        $order = $this->order(OrderType::NEW_SERVICE);
        self::assertFalse($this->walletPayment()->canPay($order), 'no wallet yet');

        $this->fund(self::PRICE - 1);
        self::assertFalse($this->walletPayment()->canPay($order));

        $this->wallets()->setCreditLimit($this->user, 1, WalletActor::system());
        self::assertTrue($this->walletPayment()->canPay($order), 'balance + credit limit covers it exactly');
    }

    public function testNotOfferedWhenSwitchedOffFrozenOrForOtherOrders(): void
    {
        $this->fund(1000000);
        $order = $this->order(OrderType::NEW_SERVICE);
        self::assertTrue($this->walletPayment()->canPay($order));

        $this->setting(WalletSettings::PAYMENT_ENABLED, 'false');
        self::assertFalse($this->walletPayment()->canPay($order), 'wallet payment switched off');
        $this->setting(WalletSettings::PAYMENT_ENABLED, 'true');
        $this->setting(WalletSettings::ENABLED, 'false');
        self::assertFalse($this->walletPayment()->canPay($order), 'wallet switched off');
        $this->setting(WalletSettings::ENABLED, 'true');

        $this->wallets()->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());
        self::assertFalse($this->walletPayment()->canPay($order), 'frozen');
        $this->wallets()->setStatus($this->user, WalletStatus::ACTIVE, WalletActor::system());

        $topUp = self::getContainer()->get(\App\Wallet\Application\WalletTopUp::class)->createOrder($this->user, 50000);
        self::assertFalse($this->walletPayment()->canPay($topUp), 'a top-up is never paid from the wallet');
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::CANCELLED, $order->getId()]);
        self::assertFalse($this->walletPayment()->canPay($this->reload($order)), 'only an order waiting for payment');
    }

    // --- paying -----------------------------------------------------------------------------------

    #[DataProvider('orderTypes')]
    public function testPayingDebitsOnceAndConfirmsThroughSettlement(string $type): void
    {
        $this->fund(200000);
        $order = $this->order($type);

        $result = $this->walletPayment()->pay($order);

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        $payments = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM payment WHERE order_id = ?', [$order->getId()]);
        self::assertCount(1, $payments, 'exactly one payment record');
        self::assertSame(Payment::METHOD_WALLET, $payments[0]['method']);
        self::assertSame(PaymentStatus::CONFIRMED, $payments[0]['status']);
        self::assertNull($payments[0]['gateway_id']);
        self::assertSame(self::PRICE, (int) $payments[0]['amount']);
        self::assertSame(WalletPayment::SOURCE, $payments[0]['confirmation_source']);
        self::assertSame([['type' => 'topup', 'amount' => 200000], ['type' => 'purchase', 'amount' => -self::PRICE]], $this->ledger());
        self::assertSame(50000, $this->balance());
        $purchase = $this->em->getConnection()->fetchAssociative("SELECT * FROM wallet_transaction WHERE type = 'purchase'");
        self::assertSame('purchase:payment:'.$payments[0]['id'], $purchase['idempotency_key']);
        self::assertSame('payment', $purchase['reference_type']);
        self::assertSame((string) $payments[0]['id'], $purchase['reference_id']);
        if (OrderType::NEW_SERVICE === $type) {
            self::assertSame(1, $this->countServicesForOrder($order));
        }

        // Paying again: the order is no longer payable, nothing is debited.
        try {
            $this->walletPayment()->pay($this->reload($order));
            self::fail('a paid order was paid again');
        } catch (WalletException $e) {
            self::assertSame('order_not_payable', $e->error);
        }
        self::assertSame(50000, $this->balance());
    }

    public function testTheDiscountedTotalIsWhatIsDebited(): void
    {
        $this->fund(200000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $order->setMetadata(['priceSnapshot' => ['baseAmount' => self::PRICE, 'discountCode' => null, 'discountCodeAmount' => 30000, 'finalAmount' => 120000]]);
        $this->em->flush();

        $result = $this->walletPayment()->pay($order);

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(80000, $this->balance());
    }

    public function testNotEnoughMoneyChangesNothing(): void
    {
        $this->fund(self::PRICE - 1);
        $order = $this->order(OrderType::NEW_SERVICE);

        try {
            $this->walletPayment()->pay($order);
            self::fail('paid without enough money');
        } catch (WalletException $e) {
            self::assertSame('wallet_insufficient', $e->error);
        }

        // Partial payment is out of scope: nothing is taken, no payment row is left behind.
        self::assertSame(self::PRICE - 1, $this->balance());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
        self::assertCount(1, $this->ledger());
    }

    public function testAFrozenWalletCannotPay(): void
    {
        $this->fund(1000000);
        $this->wallets()->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());

        try {
            $this->walletPayment()->pay($this->order(OrderType::NEW_SERVICE));
            self::fail('a frozen wallet paid');
        } catch (WalletException $e) {
            self::assertSame('wallet_frozen', $e->error);
        }
        self::assertSame(1000000, $this->balance());
    }

    /**
     * Documented behaviour: freezing stops new wallet payments, but a payment whose debit was already taken before the
     * freeze (open() committed, its settlement not finished) still settles with that one debit; the money is already
     * out of the wallet, so refusing it would only leave the order unpaid with the money held.
     */
    public function testAWalletFrozenMidCheckoutStillSettlesThePaymentAlreadyDebited(): void
    {
        $this->fund(400000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $this->walletPayment()->open($order);
        $this->wallets()->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());

        $result = $this->walletPayment()->pay($this->reload($order));

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(400000 - self::PRICE, $this->balance(), 'the one debit taken before the freeze');
        try {
            $this->walletPayment()->pay($this->order(OrderType::NEW_SERVICE));
            self::fail('a new payment from a frozen wallet');
        } catch (WalletException $e) {
            self::assertSame('wallet_frozen', $e->error);
        }
    }

    public function testAnOrderWithAReceiptUnderReviewCannotAlsoBePaidFromTheWallet(): void
    {
        $this->fund(1000000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $this->createPayment($order, $this->methods['manual_card']->getGateway(), PaymentStatus::SUBMITTED);

        try {
            $this->walletPayment()->pay($order);
            self::fail('paid twice');
        } catch (WalletException $e) {
            self::assertSame('payment_in_progress', $e->error);
        }
        self::assertSame(1000000, $this->balance());
    }

    // --- provisioning failure and refunds ------------------------------------------------------------

    #[DataProvider('orderTypes')]
    public function testAProvisioningFailureKeepsTheSingleDebitAndTheRetryNeverDebitsAgain(string $type): void
    {
        $this->fund(200000);
        $order = $this->order($type);
        $this->driver()->failNext();

        $failed = $this->walletPayment()->pay($order);

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome, $failed->message);
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(50000, $this->balance(), 'the money was taken once');

        $retry = $this->settlement()->retryProvisioning($failed->payment ?? throw new \LogicException(), 1, null, 'test_retry');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(50000, $this->balance(), 'no second debit on retry');
        self::assertCount(2, $this->ledger());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function refundFlags(): iterable
    {
        yield 'reject' => [false];
        yield 'reject as refunded' => [true];
    }

    #[DataProvider('refundFlags')]
    public function testAnAdminRejectionGivesTheDebitBackOnce(bool $refunded): void
    {
        $this->fund(200000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $this->driver()->failNext();
        $failed = $this->walletPayment()->pay($order);
        $payment = $failed->payment ?? throw new \LogicException();

        $rejected = $this->settlement()->reject($payment, 'cannot deliver', 7, null, 'test_admin', $refunded);
        $again = $this->settlement()->reject($rejected->payment ?? $payment, 'cannot deliver', 7, null, 'test_admin', $refunded);

        self::assertSame(SettlementOutcome::REJECTED, $rejected->outcome, $rejected->message);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $again->outcome);
        self::assertSame(200000, $this->balance());
        self::assertSame([['type' => 'topup', 'amount' => 200000], ['type' => 'purchase', 'amount' => -self::PRICE], ['type' => 'reversal', 'amount' => self::PRICE]], $this->ledger());
        $reversal = $this->em->getConnection()->fetchAssociative("SELECT * FROM wallet_transaction WHERE type = 'reversal'");
        self::assertSame('reversal:payment:'.$payment->getId(), $reversal['idempotency_key']);
        self::assertSame(7, (int) $reversal['created_by_admin_id']);
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, '150,000')), 'the customer is told the money is back');
    }

    public function testAnUnsettledWalletPaymentThatIsCancelledOrExpiredGivesTheDebitBack(): void
    {
        $this->fund(400000);

        // Debited, but the settlement never ran (e.g. it failed before fulfilment): the payment is still pending.
        $cancelled = $this->order(OrderType::NEW_SERVICE);
        $this->walletPayment()->open($cancelled);
        self::assertSame(250000, $this->balance());
        self::assertSame(SettlementOutcome::REJECTED, $this->settlement()->cancelByCustomer($this->reload($cancelled))->outcome);
        self::assertSame(400000, $this->balance());

        $expired = $this->order(OrderType::NEW_SERVICE);
        $this->walletPayment()->open($expired);
        self::assertSame(250000, $this->balance());
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'), $expired->getId()]);
        $this->em->clear();
        self::getContainer()->get(IncompleteOrderExpiryService::class)->expire(false, 24);

        self::assertSame(OrderStatus::EXPIRED, $this->row('`order`', (int) $expired->getId())['status']);
        self::assertSame(400000, $this->balance());
        self::assertSame(['topup', 'purchase', 'reversal', 'purchase', 'reversal'], array_column($this->ledger(), 'type'));
    }

    public function testRetryingAnUnsettledWalletPaymentReusesItsDebit(): void
    {
        $this->fund(200000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $payment = $this->walletPayment()->open($order);

        $result = $this->walletPayment()->pay($this->reload($order));

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame($payment->getId(), $result->payment?->getId(), 'the same payment, not a second one');
        self::assertSame(50000, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    public function testAConfirmedWalletOrderCannotBeCancelled(): void
    {
        $this->fund(200000);
        $order = $this->order(OrderType::NEW_SERVICE);
        $this->walletPayment()->pay($order);

        $cancel = $this->settlement()->cancelByCustomer($this->reload($order));

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $cancel->outcome);
        self::assertSame(50000, $this->balance());
    }

    // --- concurrency ----------------------------------------------------------------------------

    #[Group('subprocess')]
    public function testTwoOrdersPaidInParallelCannotOverdraw(): void
    {
        $this->fund(200000);
        $first = $this->order(OrderType::NEW_SERVICE);
        $second = $this->order(OrderType::NEW_SERVICE);

        $outcomes = $this->payInParallel([(int) $first->getId(), (int) $second->getId()]);
        sort($outcomes);

        self::assertSame(['confirmed', 'wallet_insufficient'], $outcomes);
        self::assertSame(50000, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    #[Group('subprocess')]
    public function testOneOrderPaidTwiceInParallelIsDebitedOnce(): void
    {
        $this->fund(400000);
        $order = $this->order(OrderType::NEW_SERVICE);

        $outcomes = $this->payInParallel([(int) $order->getId(), (int) $order->getId()]);
        sort($outcomes);

        self::assertContains('confirmed', $outcomes);
        self::assertSame(250000, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    /**
     * @param list<int> $orderIds
     *
     * @return list<string>
     */
    private function payInParallel(array $orderIds): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($orderIds as $orderId) {
            $process = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-pay.php', (string) $orderId], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '1000'], null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $outcomes[] = trim($process->getOutput());
        }

        return $outcomes;
    }
}
