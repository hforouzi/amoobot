<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\StorePaymentMethod;
use App\Entity\User;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Review finding I3: app:wallet:reconcile also cross-checks payments against the ledger, class by class, and reports
 * (exit code 1) without fixing anything. Each test builds a real state with the services, then breaks one link the
 * way a bug or a hand edit would.
 */
final class WalletReconcileTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use MiniAppShopFixtures;

    private const PRICE = 150000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, 400000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    /**
     * @return array<string, list<int|null>> problem => payment ids
     */
    private function problems(): array
    {
        $found = [];
        foreach (self::getContainer()->get(WalletReconciler::class)->paymentMismatches() as $mismatch) {
            $found[$mismatch['problem']][] = $mismatch['paymentId'];
        }

        return $found;
    }

    private function confirmedTopUp(): Payment
    {
        $order = self::getContainer()->get(WalletTopUp::class)->createOrder($this->user, 50000);
        $payment = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        $result = $this->settlement()->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-'.$payment->getId(), amount: '50000', currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);

        return $payment;
    }

    private function openWalletPayment(): Payment
    {
        return self::getContainer()->get(WalletPayment::class)->open($this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE));
    }

    public function testConsistentPaymentsAndLedgerReportNothing(): void
    {
        $this->confirmedTopUp();
        self::getContainer()->get(WalletPayment::class)->pay($this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE));
        $cancelled = $this->openWalletPayment();
        $this->settlement()->cancelByCustomer($cancelled->getOrder());

        self::assertSame([], self::getContainer()->get(WalletReconciler::class)->paymentMismatches());
        self::assertSame(0, $this->runCommand()->getStatusCode());
    }

    public function testAConfirmedTopUpWithoutItsCredit(): void
    {
        $order = self::getContainer()->get(WalletTopUp::class)->createOrder($this->user, 50000);
        $payment = $this->createPayment($order, $this->methods[PaymentGatewayType::ZIBAL]->getGateway(), 'confirmed');

        self::assertSame([WalletReconciler::TOPUP_NOT_CREDITED => [$payment->getId()]], $this->problems());
    }

    public function testATopUpCreditWhosePaymentIsNotConfirmed(): void
    {
        $payment = $this->confirmedTopUp();
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'pending' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT => [$payment->getId()]], $this->problems());
    }

    public function testAWalletPaymentWithoutItsDebit(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $payment = $this->createPayment($order, null, 'confirmed');
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'wallet', gateway_type = 'wallet' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::WALLET_PAYMENT_WITHOUT_DEBIT => [$payment->getId()]], $this->problems());
    }

    /** The review's scratch case: a debit stranded on a payment whose method was rewritten, then rejected. */
    public function testARejectedPaymentWhoseDebitWasNeverGivenBack(): void
    {
        $payment = $this->openWalletPayment();
        $this->em->getConnection()->executeStatement("UPDATE payment SET method = 'zibal', gateway_type = 'zibal', status = 'rejected' WHERE id = ?", [$payment->getId()]);

        self::assertSame([WalletReconciler::DEBIT_NOT_RETURNED => [$payment->getId()]], $this->problems());
    }

    public function testADebitGivenBackWhileItsPaymentIsStillLive(): void
    {
        $payment = $this->openWalletPayment();
        self::getContainer()->get(WalletService::class)->releasePurchase((int) $payment->getId(), WalletActor::system(), 'test');

        self::assertSame([WalletReconciler::REVERSAL_OF_LIVE_PAYMENT => [$payment->getId()]], $this->problems());
    }

    public function testAPurchaseDebitWithoutAPayment(): void
    {
        self::getContainer()->get(WalletService::class)->debit($this->user, 1000, WalletTransactionType::PURCHASE, 'purchase:payment:987654321', WalletActor::system());

        $mismatches = self::getContainer()->get(WalletReconciler::class)->paymentMismatches();

        self::assertCount(1, $mismatches);
        self::assertSame(WalletReconciler::PURCHASE_WITHOUT_PAYMENT, $mismatches[0]['problem']);
        self::assertNull($mismatches[0]['paymentId']);
        self::assertNotNull($mismatches[0]['transactionId']);
    }

    /** Second review, blocker 1: a held debit nothing would ever give back (the order is no longer payable). */
    public function testAWalletPaymentPendingWhileItsOrderIsNoLongerWaitingForPayment(): void
    {
        $payment = $this->openWalletPayment();
        self::assertSame([], $this->problems(), 'pending while the order waits for payment: legitimate');
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'provisioned' WHERE id = ?", [$payment->getOrder()->getId()]);

        self::assertSame([WalletReconciler::PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING => [$payment->getId()]], $this->problems());
        $tester = $this->runCommand();
        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains(WalletReconciler::PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING, $tester->getDisplay());
    }

    /** Third review, minor: a held debit must be exactly its payment's amount; a given-back one is not checked. */
    public function testAHeldDebitWhoseAmountIsNotItsPaymentsAmount(): void
    {
        $payment = $this->openWalletPayment();
        $released = $this->openWalletPayment();
        self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($released->getOrder());
        self::assertSame([], $this->problems(), 'debit equal to the payable amount; a released one: legitimate');

        $this->em->getConnection()->executeStatement('UPDATE payment SET payable_amount = payable_amount + 1 WHERE id IN (?, ?)', [$payment->getId(), $released->getId()]);

        self::assertSame([WalletReconciler::DEBIT_AMOUNT_MISMATCH => [$payment->getId()]], $this->problems());
        $tester = $this->runCommand();
        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains(WalletReconciler::DEBIT_AMOUNT_MISMATCH, $tester->getDisplay());
        self::assertConsoleContains('held debit of '.self::PRICE.' but the payment is for '.(self::PRICE + 1), $tester->getDisplay());
    }

    /**
     * A wallet payment whose provisioning failed: its debit is held for an admin retry (legitimate).
     *
     * @return array{Payment, Payment} [wallet payment, the order's earlier Zibal payment (pending)]
     */
    private function provisioningFailedWalletPayment(): array
    {
        // A failed settlement clears the entity manager: the fixtures are reloaded.
        $user = $this->em->find(User::class, $this->user->getId()) ?? throw new \LogicException();
        $plan = $this->em->find(Plan::class, $this->plan->getId()) ?? throw new \LogicException();
        $method = $this->em->find(StorePaymentMethod::class, $this->methods[PaymentGatewayType::ZIBAL]->getId()) ?? throw new \LogicException();
        $order = $this->createOrder($user, $plan, OrderType::NEW_SERVICE, self::PRICE);
        $zibal = self::getContainer()->get(PaymentStarter::class)->start($order, $method)->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $this->em->refresh($order);
        $result = self::getContainer()->get(WalletPayment::class)->pay($order);
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);

        return [$result->payment ?? throw new \LogicException(), $zibal];
    }

    /**
     * Fourth review: a wallet payment kept for an admin decision (provisioning_failed / needs_review) still holds its
     * debit while its order failed, was cancelled or expired (e.g. an edit of the order's status in the admin): nothing
     * would give that debit back.
     */
    public function testAHeldDebitOfAWalletPaymentWhoseOrderIsClosed(): void
    {
        self::getContainer()->get(WalletService::class)->credit($this->user, 100000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        [$failed] = $this->provisioningFailedWalletPayment();
        [$review] = $this->provisioningFailedWalletPayment();
        [$released] = $this->provisioningFailedWalletPayment();
        $released = $this->em->find(Payment::class, $released->getId()) ?? throw new \LogicException();
        self::getContainer()->get(PaymentSettlementService::class)->reject($released, 'refunded to the wallet', 1, null, 'test_admin', true);
        self::assertSame([], $this->problems(), 'held for a retry, or given back: legitimate');

        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE payment SET status = 'needs_review' WHERE id = ?", [$review->getId()]);
        self::assertSame([], $this->problems(), 'in review with its order still in provisioning_failed: legitimate');

        foreach (['failed', 'cancelled', 'expired'] as $status) {
            $connection->executeStatement('UPDATE `order` SET status = ? WHERE id IN (?, ?, ?)', [$status, $failed->getOrder()->getId(), $review->getOrder()->getId(), $released->getOrder()->getId()]);
            self::assertSame([WalletReconciler::HELD_DEBIT_ORDER_CLOSED => [$failed->getId(), $review->getId()]], $this->problems(), $status);
        }
        $tester = $this->runCommand();
        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains(WalletReconciler::HELD_DEBIT_ORDER_CLOSED, $tester->getDisplay());
        self::assertConsoleContains('wallet payment is provisioning_failed with its debit of '.self::PRICE.' held but order #'.$failed->getOrder()->getId().' is expired', $tester->getDisplay());
    }

    /** The original B1 state: another payment of the order is confirmed while a wallet payment still holds its debit. */
    public function testAHeldDebitBesideAConfirmedPaymentOfTheSameOrder(): void
    {
        [$wallet, $zibal] = $this->provisioningFailedWalletPayment();
        self::assertSame([], $this->problems());

        // As the pre-fix code left it (or a hand edit): the Zibal payment confirmed, the wallet debit not given back.
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'confirmed' WHERE id = ?", [$zibal->getId()]);
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'provisioned' WHERE id = ?", [$wallet->getOrder()->getId()]);

        self::assertSame([WalletReconciler::HELD_DEBIT_ORDER_PAID_OTHERWISE => [$wallet->getId()]], $this->problems());
        // The same while the wallet payment is in review.
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'needs_review' WHERE id = ?", [$wallet->getId()]);
        $tester = $this->runCommand();
        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains(WalletReconciler::HELD_DEBIT_ORDER_PAID_OTHERWISE, $tester->getDisplay());
        self::assertConsoleContains('wallet payment is needs_review with its debit of '.self::PRICE.' held but payment #'.$zibal->getId().' of order #'.$wallet->getOrder()->getId().' is confirmed', $tester->getDisplay());
        self::assertConsoleNotContains(WalletReconciler::HELD_DEBIT_ORDER_CLOSED, $tester->getDisplay());
    }

    public function testTheCommandReportsEveryClassWithIdsAndExitsWithOne(): void
    {
        $topUp = $this->confirmedTopUp();
        $this->em->getConnection()->executeStatement("UPDATE payment SET status = 'pending' WHERE id = ?", [$topUp->getId()]);
        $live = $this->openWalletPayment();
        self::getContainer()->get(WalletService::class)->releasePurchase((int) $live->getId(), WalletActor::system(), 'test');

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertConsoleContains(WalletReconciler::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT, $display);
        self::assertConsoleContains(WalletReconciler::REVERSAL_OF_LIVE_PAYMENT, $display);
        self::assertConsoleContains('#'.$topUp->getId(), $display);
        self::assertConsoleContains('#'.$live->getId(), $display);
        self::assertConsoleContains('Nothing was changed', $display);
        self::assertSame('pending', $this->row('payment', (int) $topUp->getId())['status'], 'report only');
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:wallet:reconcile'));
        $tester->execute([]);

        return $tester;
    }
}
