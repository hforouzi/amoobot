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
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;

/**
 * Variations of the review findings that the per-finding tests do not cover: B2 in other orders (admin reject, then
 * pay / approve / retry), a provisioning failure rejected, a wallet frozen mid-checkout, discounted / renewal /
 * add-traffic orders paid from the wallet, top-up credit math (Rial, overpaid, underpaid then approved, double
 * webhook), reconcile quiet on legitimate states, and the two first-review B1 tests rewritten for the fixed behaviour.
 */
final class WalletReviewVariationsTest extends IntegrationTestCase
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
    }

    private function svc(string $id): object
    {
        return self::getContainer()->get($id);
    }

    private function settlement(): PaymentSettlementService
    {
        return $this->svc(PaymentSettlementService::class);
    }

    private function fund(int $amount = self::FUNDED): void
    {
        $this->svc(WalletService::class)->credit($this->user, $amount, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
    }

    private function balance(): int
    {
        return (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]) ?: 0);
    }

    private function freshOrder(Order $order): Order
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

    private function paid(Payment $payment, string $amount, string $currency = 'IRT', string $tx = 'tx-1'): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: $tx, amount: $amount, currency: $currency));
    }

    /** @return list<array<string, mixed>> */
    private function reconcile(): array
    {
        return $this->svc(WalletReconciler::class)->report()['payments'];
    }

    // ---- B2 variations ---------------------------------------------------------------------------------------------

    public function testAdminRejectBetweenOpenAndSettleThenRetryProvisioningCannotProvision(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $payment = $this->svc(WalletPayment::class)->open($order);
        $this->settlement()->reject($this->freshPayment((int) $payment->getId()), 'no', 1, null, 'test_admin');
        self::assertSame(self::FUNDED, $this->balance());

        // pay() again on the FAILED order, settle, approve, retry: none may provision.
        try {
            $this->svc(WalletPayment::class)->pay($this->freshOrder($order));
        } catch (\App\Wallet\Domain\WalletException) {
        }
        $approve = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');
        $retry = $this->settlement()->retryProvisioning($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');
        self::assertNotSame(SettlementOutcome::CONFIRMED, $approve->outcome);
        self::assertNotSame(SettlementOutcome::CONFIRMED, $retry->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(self::FUNDED, $this->balance());
    }

    public function testProvisioningFailedWalletPaymentRejectedGivesTheMoneyBackOnceAndRetryIsRefused(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->driver()->failNext(1);
        $result = $this->svc(WalletPayment::class)->pay($order);
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        $paymentId = (int) $result->payment?->getId();
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'the debit stays while provisioning failed');

        $this->settlement()->reject($this->freshPayment($paymentId), 'refund', 1, null, 'test_admin');
        $this->settlement()->reject($this->freshPayment($paymentId), 'refund', 1, null, 'test_admin');
        self::assertSame(self::FUNDED, $this->balance());
        $retry = $this->settlement()->retryProvisioning($this->freshPayment($paymentId), 1, null, 'test_admin');
        self::assertNotSame(SettlementOutcome::CONFIRMED, $retry->outcome);
        self::assertSame([], $this->reconcile());
    }

    public function testFrozenMidCheckoutCancelStillRefunds(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->svc(WalletPayment::class)->open($order);
        $this->svc(WalletService::class)->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());

        $cancel = $this->settlement()->cancelByCustomer($this->freshOrder($order));
        self::assertSame(SettlementOutcome::REJECTED, $cancel->outcome, $cancel->message);
        self::assertSame(self::FUNDED, $this->balance());
    }

    /** The debit was taken before the freeze: paying again reuses it (no new debit) and settles the order. */
    public function testFrozenMidCheckoutPayAgainSettlesWithTheDebitAlreadyTaken(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->svc(WalletPayment::class)->open($order);
        $this->svc(WalletService::class)->setStatus($this->user, WalletStatus::FROZEN, WalletActor::system());

        $result = $this->svc(WalletPayment::class)->pay($this->freshOrder($order));

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'charged once');
    }

    // ---- order kinds paid from the wallet ------------------------------------------------------------------------

    public function testDiscountedOrderDebitsTheFinalAmountAndMarksTheCodeUsed(): void
    {
        $this->fund();
        $code = $this->discountCode('SAVE', 'fixed', 30000);
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $order->setMetadata(['priceSnapshot' => ['afterGlobalDiscountAmount' => self::PRICE, 'discountCode' => 'SAVE', 'discountCodeAmount' => 30000, 'finalAmount' => 120000]]);
        $this->em->flush();

        $result = $this->svc(WalletPayment::class)->pay($this->freshOrder($order));
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(self::FUNDED - 120000, $this->balance());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM discount_usage WHERE discount_code_id = ?', [$code->getId()]));
        self::assertSame([], $this->reconcile());
    }

    public function testRenewalAndAddTrafficPaidFromTheWallet(): void
    {
        $this->fund(400000);
        $service = $this->createActiveService($this->user, $this->inbound);
        foreach ([OrderType::RENEWAL, OrderType::ADD_TRAFFIC] as $type) {
            $order = $this->createOrder($this->user, $this->plan, $type, 100000, $service);
            $result = $this->svc(WalletPayment::class)->pay($order);
            self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $type.': '.$result->message);
            // A second tap: no second debit, no second renewal.
            try {
                $again = $this->svc(WalletPayment::class)->pay($this->freshOrder($order))->outcome->value;
            } catch (\App\Wallet\Domain\WalletException $e) {
                $again = $e->getMessage();
            }
            self::assertNotSame('confirmed', $again);
        }
        self::assertSame(200000, $this->balance());
        self::assertSame([], $this->reconcile());
    }

    // ---- top-ups: I1 math and double webhooks --------------------------------------------------------------------

    private function topUp(int $amount, string $method): Payment
    {
        $this->user = $this->em->find(\App\Entity\User::class, $this->user->getId()) ?? throw new \LogicException();
        $order = $this->svc(WalletTopUp::class)->createOrder($this->user, $amount);

        return $this->freshPayment((int) ($this->svc(PaymentStarter::class)->start($order, $this->methods[$method])->payment?->getId()));
    }

    public function testZibalTopUpReportedInRialCreditsTheTomanAmount(): void
    {
        $payment = $this->topUp(50000, PaymentGatewayType::ZIBAL);
        $result = $this->settlement()->settle($payment, $this->paid($payment, '500000', 'IRR'), 'test_webhook');
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(50000, $this->balance());
    }

    public function testDoubleWebhookCreditsOnce(): void
    {
        $payment = $this->topUp(50000, PaymentGatewayType::ZIBAL);
        $this->settlement()->settle($payment, $this->paid($payment, '50000'), 'test_webhook');
        $second = $this->settlement()->settle($this->freshPayment((int) $payment->getId()), $this->paid($payment, '50000'), 'test_webhook');
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $second->outcome);
        self::assertSame(50000, $this->balance());
    }

    public function testOverpaidCryptoTopUpCreditsTheOrderedAmount(): void
    {
        $payment = $this->topUp(50000, PaymentGatewayType::NOWPAYMENTS);
        $result = $this->settlement()->settle($payment, $this->paid($payment, sprintf('%.8F', (float) $payment->getExpectedAmount() * 1.5), (string) $payment->getExpectedCurrency()), 'test_webhook');
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(50000, $this->balance());
    }

    /** Admin approves a top-up parked for a large underpayment: what was received, not the order. */
    public function testAdminApprovalOfAHeavilyUnderpaidTopUpCreditsWhatWasReceived(): void
    {
        $payment = $this->topUp(50000, PaymentGatewayType::NOWPAYMENTS);
        $half = sprintf('%.8F', (float) $payment->getExpectedAmount() / 2);
        $parked = $this->settlement()->settle($payment, $this->paid($payment, $half, (string) $payment->getExpectedCurrency()), 'test_webhook');
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $parked->outcome);
        $approved = $this->settlement()->approveByAdmin($this->freshPayment((int) $payment->getId()), 1, null, 'test_admin');
        self::assertSame(SettlementOutcome::CONFIRMED, $approved->outcome, $approved->message);
        self::assertSame(25000, $this->balance());
        self::assertSame([], $this->reconcile());
    }

    /**
     * A gateway reports "paid" with a tiny amount; the admin approves it from review. floor() gives 0 Toman. The admin
     * must get a clear answer (approve impossible / credit 0) - not an ERROR "try again" that can never succeed, nor an
     * alert claiming "the payment is still pending".
     */

    // ---- reconcile: legitimate states must not be reported -------------------------------------------------------

    public function testReconcileIsQuietOnLegitimateParkedAndReviewStates(): void
    {
        $this->fund(600000);
        // 1. a gateway payment paid after the customer cancelled (parked NEEDS_REVIEW)
        $o1 = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $p1 = $this->svc(PaymentStarter::class)->start($o1, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        $this->settlement()->cancelByCustomer($this->freshOrder($o1));
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->settlement()->settle($this->freshPayment((int) $p1->getId()), $this->paid($p1, (string) self::PRICE, 'IRT', 'z-late'), 'test_webhook')->outcome);
        // 2. a confirmed wallet payment + a duplicate gateway payment for the same order (parked)
        $o2 = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $p2 = $this->svc(PaymentStarter::class)->start($o2, $this->methods[PaymentGatewayType::ZIBAL])->payment ?? throw new \LogicException();
        self::assertSame(SettlementOutcome::CONFIRMED, $this->svc(WalletPayment::class)->pay($this->freshOrder($o2))->outcome);
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->settlement()->settle($this->freshPayment((int) $p2->getId()), $this->paid($p2, (string) self::PRICE, 'IRT', 'z-dup'), 'test_webhook')->outcome);
        // 4. a top-up parked for underpayment
        $p4 = $this->topUp(50000, PaymentGatewayType::NOWPAYMENTS);
        $this->settlement()->settle($p4, $this->paid($p4, sprintf('%.8F', (float) $p4->getExpectedAmount() / 2), (string) $p4->getExpectedCurrency()), 'test_webhook');
        // 5. a pending (debited, unsettled) wallet payment
        $this->user = $this->em->find(\App\Entity\User::class, $this->user->getId()) ?? throw new \LogicException();
        $this->plan = $this->em->find(\App\Entity\Plan::class, $this->plan->getId()) ?? throw new \LogicException();
        $this->svc(WalletPayment::class)->open($this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, 10000));
        // 3. a wallet payment in PROVISIONING_FAILED (last: its rollback clears the entity manager)
        $o3 = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->driver()->failNext(1);
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->svc(WalletPayment::class)->pay($o3)->outcome);

        self::assertSame([], $this->reconcile());
    }


    // ---- the first review's B1 tests, for the fixed behaviour -----------------------------------------------------

    /** B1: while a wallet payment is debited and unsettled, a gateway checkout is refused, so nothing is charged twice. */
    public function testB1AGatewayCheckoutWhileAWalletPaymentIsPendingIsRefusedAndChargesNothingTwice(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $wallet = $this->svc(WalletPayment::class)->open($order);

        $start = $this->svc(PaymentStarter::class)->start($this->freshOrder($order), $this->methods[PaymentGatewayType::ZIBAL]);
        self::assertSame(PaymentStart::KIND_WALLET_PAYMENT_PENDING, $start->kind);
        self::assertNull($start->payment);

        $paid = $this->svc(WalletPayment::class)->pay($this->freshOrder($order));
        self::assertSame(SettlementOutcome::CONFIRMED, $paid->outcome, $paid->message);
        self::assertSame($wallet->getId(), $paid->payment?->getId());
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'charged once');
    }

    /** B1: after the cancel the debit is reversed, so the payment-vs-ledger check (not only the balances) is clean. */
    public function testB1ReconcileIsCleanAfterCancellingAnUnsettledWalletPayment(): void
    {
        $this->fund();
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $this->svc(WalletPayment::class)->open($order);
        $this->settlement()->cancelByCustomer($this->freshOrder($order));

        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], $this->svc(WalletReconciler::class)->report());
    }
}
