<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\MiniAppSession;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Security\MiniAppUser;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\IncompleteOrderExpiryService;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Review finding B1: a wallet payment that was debited but not settled yet (settle() errored, the worker died during
 * provisioning) must never be taken over by a gateway checkout. PaymentStarter used to reuse it as "the open payment"
 * and rewrite its method, after which nothing gave the debit back: the customer paid twice or lost the money.
 *
 * Now a gateway checkout is refused while such a payment is pending (the customer pays from the wallet again, which
 * reuses the debit, or cancels, which returns it), and releasing a debit is keyed on the ledger row, not on the
 * payment's method.
 */
final class WalletGatewayHandoverTest extends IntegrationTestCase
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

    private function starter(): PaymentStarter
    {
        return self::getContainer()->get(PaymentStarter::class);
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
     * A wallet payment debited by open() whose settlement never finished.
     *
     * @return array{Order, Payment}
     */
    private function unsettledWalletPayment(): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $payment = self::getContainer()->get(WalletPayment::class)->open($order);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());

        return [$order, $payment];
    }

    public function testAGatewayCheckoutIsRefusedWhileAWalletPaymentIsPending(): void
    {
        [$order, $walletPayment] = $this->unsettledWalletPayment();

        $start = $this->starter()->start($this->fresh($order), $this->methods[PaymentGatewayType::ZIBAL]);

        self::assertSame(PaymentStart::KIND_WALLET_PAYMENT_PENDING, $start->kind);
        self::assertNull($start->payment);
        self::assertFalse($start->isReady());
        $row = $this->row('payment', (int) $walletPayment->getId());
        self::assertSame([Payment::METHOD_WALLET, Payment::METHOD_WALLET, PaymentStatus::PENDING], [$row['method'], $row['gateway_type'], $row['status']], 'the wallet payment is untouched');
        self::assertSame(1, $this->rows('payment'));
    }

    public function testCancellingAfterTheRefusedCheckoutGivesTheDebitBack(): void
    {
        [$order] = $this->unsettledWalletPayment();
        $this->starter()->start($this->fresh($order), $this->methods[PaymentGatewayType::ZIBAL]);

        $cancel = $this->settlement()->cancelByCustomer($this->fresh($order));

        self::assertSame(SettlementOutcome::REJECTED, $cancel->outcome);
        self::assertSame(self::FUNDED, $this->balance());
    }

    public function testPayingFromTheWalletAgainSettlesWithTheSameSingleDebit(): void
    {
        [$order] = $this->unsettledWalletPayment();
        $this->starter()->start($this->fresh($order), $this->methods[PaymentGatewayType::ZIBAL]);

        $result = self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'charged once');
        self::assertSame(1, $this->rows('payment'));
    }

    public function testAnEarlierGatewayPaymentIsNotReusedOnceTheWalletPaymentIsPending(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $gatewayPayment = $this->starter()->start($order, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        $walletPayment = self::getContainer()->get(WalletPayment::class)->open($this->fresh($order));
        self::assertNotSame($gatewayPayment->getId(), $walletPayment->getId());

        $again = $this->starter()->start($this->fresh($order), $this->methods[PaymentGatewayType::CUSTOM_API]);

        self::assertSame(PaymentStart::KIND_WALLET_PAYMENT_PENDING, $again->kind);
        self::assertSame(Payment::METHOD_WALLET, $this->row('payment', (int) $walletPayment->getId())['method']);
    }

    public function testAGatewayCheckoutAfterTheWalletPaymentWasCancelledStartsAFreshPayment(): void
    {
        [$order, $walletPayment] = $this->unsettledWalletPayment();
        $this->settlement()->cancelByCustomer($this->fresh($order));
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', ['waiting_payment', $order->getId()]);

        $start = $this->starter()->start($this->fresh($order), $this->methods[PaymentGatewayType::ZIBAL]);

        self::assertSame(PaymentStart::KIND_ONLINE, $start->kind);
        self::assertNotSame($walletPayment->getId(), $start->payment?->getId());
        self::assertSame(Payment::METHOD_WALLET, $this->row('payment', (int) $walletPayment->getId())['method']);
    }

    public function testTheMiniAppRefusesTheGatewayCheckoutWithAClearCode(): void
    {
        [$order] = $this->unsettledWalletPayment();
        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => self::CUSTOMER]) ?? throw new \LogicException();
        $user = new MiniAppUser($account, new MiniAppSession(str_repeat('0', 64), self::CUSTOMER, str_repeat('0', 64), new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour')), null);

        try {
            self::getContainer()->get(MiniAppCheckout::class)->pay($user, (int) $order->getId(), (int) $this->methods[PaymentGatewayType::ZIBAL]->getId());
            self::fail('expected a refusal');
        } catch (CheckoutFailure $e) {
            self::assertSame('wallet_payment_pending', $e->error);
            self::assertSame(409, $e->status);
        }
        self::assertSame(1, $this->rows('payment'));
    }

    // --- releasing a debit is keyed on the ledger, not on payment.method ---------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function releasePaths(): iterable
    {
        yield 'customer cancel' => ['cancel'];
        yield 'admin reject' => ['reject'];
        yield 'gateway failure' => ['gateway_failure'];
        yield 'order expiry' => ['expiry'];
    }

    /**
     * A payment row whose method no longer says "wallet" (written by the old takeover, or by anything else) still has
     * its purchase debit in the ledger: every path that rejects it gives the debit back, exactly once.
     */
    #[DataProvider('releasePaths')]
    public function testTheDebitIsReleasedOnceEvenWhenThePaymentNoLongerSaysWallet(string $path): void
    {
        [$order, $payment] = $this->unsettledWalletPayment();
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'zibal', gateway_type = 'zibal' WHERE id = ?", [$payment->getId()]);

        $this->rejectBy($path, $order, (int) $payment->getId());
        $this->rejectBy($path, $order, (int) $payment->getId());

        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
    }

    #[DataProvider('releasePaths')]
    public function testAGatewayPaymentWithoutADebitIsRejectedWithoutTouchingAnyWallet(string $path): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $payment = $this->starter()->start($order, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();

        $this->rejectBy($path, $order, (int) $payment->getId());

        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
    }

    private function rejectBy(string $path, Order $order, int $paymentId): void
    {
        match ($path) {
            'cancel' => $this->settlement()->cancelByCustomer($this->fresh($order)),
            'reject' => $this->settlement()->reject($this->freshPayment($paymentId), 'not paid', 1, null, 'test_admin'),
            'gateway_failure' => $this->settlement()->recordGatewayFailure($this->freshPayment($paymentId), 'test_webhook'),
            'expiry' => $this->expire($order),
            default => throw new \LogicException('Unknown path '.$path),
        };
    }

    private function expire(Order $order): void
    {
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        self::getContainer()->get(IncompleteOrderExpiryService::class)->expire(false, 1);
    }
}
