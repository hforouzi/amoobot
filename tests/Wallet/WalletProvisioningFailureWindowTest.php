<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fourth review, N1: a payment's fulfilment fails, its transaction is rolled back (the order and payment locks are
 * released), and only then does recordProvisioningFailure() lock them again to record the failure. Another payment of
 * the same order queued on the order lock runs in that window: here an admin's retry of the wallet payment, which
 * provisions the order. Recording the failure afterwards moved the order from provisioned back to provisioning_failed
 * and kept the gateway payment as paid: the customer had paid twice and nothing reported it.
 *
 * Now recordProvisioningFailure() checks, under the locks, whether the order was fulfilled meanwhile: a gateway payment
 * is parked in review as a duplicate (for a refund), a wallet payment gives its debit back. Real processes: this one
 * settles with a slow failing panel, a subprocess waits on the order lock.
 */
final class WalletProvisioningFailureWindowTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;
    use SubprocessAssertions;

    private const PRICE = 150000;
    private const FUNDED = 200000;
    /** This process's panel call: long enough for the subprocess to boot and queue on the order lock. */
    private const PANEL_DELAY_MS = 6000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, self::FUNDED, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TEST_PANEL_DELAY_MS']);
        parent::tearDown();
    }

    /** @return array{Order, Payment, Payment} [order, Zibal payment (pending), wallet payment (provisioning_failed)] */
    private function walletPaymentFailedAfterZibal(): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $method = $this->em->find(StorePaymentMethod::class, $this->methods[PaymentGatewayType::ZIBAL]->getId()) ?? throw new \LogicException();
        $zibal = self::getContainer()->get(PaymentStarter::class)->start($order, $method)->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $fresh = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $this->em->refresh($fresh);
        $result = self::getContainer()->get(WalletPayment::class)->pay($fresh);
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        $this->em->clear();

        return [$order, $zibal, $result->payment ?? throw new \LogicException()];
    }

    /** Starts a helper in its own process; it boots, then blocks on the order lock this process is about to hold. */
    private function queued(string $script, string ...$arguments): Process
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $process = new Process([$php, dirname(__DIR__).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '0'], null, 120);
        $process->start();

        return $process;
    }

    private function payment(int $id): Payment
    {
        $payment = $this->em->find(Payment::class, $id) ?? throw new \LogicException();
        $this->em->refresh($payment);

        return $payment;
    }

    private function balance(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]);
    }

    public function testAWalletRetryThatProvisionsInTheWindowMakesTheFailedGatewayPaymentADuplicate(): void
    {
        [$order, $zibal, $wallet] = $this->walletPaymentFailedAfterZibal();
        $retry = $this->queued('retry-provisioning.php', (string) $wallet->getId());
        $_SERVER['TEST_PANEL_DELAY_MS'] = (string) self::PANEL_DELAY_MS;
        $this->driver()->failNext(1);

        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($this->payment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');
        $retry->wait();
        $this->em->clear();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($retry, 'retry-provisioning.php'), 'the admin retry ran in the window and provisioned the order');
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome, $result->message);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status'], 'no regression to provisioning_failed');
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $wallet->getId())['status']);
        $zibalRow = $this->row('payment', (int) $zibal->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $zibalRow['status'], 'parked as a duplicate, for a refund');
        self::assertStringContainsString('duplicate', (string) $zibalRow['review_reason']);
        self::assertSame((string) self::PRICE, (string) (int) $zibalRow['verified_amount'], 'what the gateway received is recorded');
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'the wallet paid the order once');
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
        self::assertNotSame([], array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'Payment ID: '.$zibal->getId()) && str_contains($t, 'needs_review')), 'the admins are alerted');
    }

    /** The mirror: the admin's retry of the wallet payment fails; a Zibal webhook queued on the lock pays the order. */
    public function testAGatewayPaymentThatProvisionsInTheWindowGivesTheFailedWalletRetrysDebitBack(): void
    {
        [$order, $zibal, $wallet] = $this->walletPaymentFailedAfterZibal();
        $webhook = $this->queued('settle-payment.php', (string) $zibal->getId(), (string) self::PRICE);
        $_SERVER['TEST_PANEL_DELAY_MS'] = (string) self::PANEL_DELAY_MS;
        $this->driver()->failNext(1);

        $result = self::getContainer()->get(PaymentSettlementService::class)->retryProvisioning($this->payment((int) $wallet->getId()), 1, null, 'test_admin');
        $webhook->wait();
        $this->em->clear();

        self::assertSame(SettlementOutcome::CONFIRMED->value, self::assertSubprocessClean($webhook, 'settle-payment.php'));
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $result->outcome, $result->message);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $wallet->getId())['status']);
        self::assertSame(self::FUNDED, $this->balance(), 'the wallet debit came back: the customer paid once, by Zibal');
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }
}
