<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\WalletTransaction;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\Money\Decimal;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\IncompleteOrderExpiryService;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Application\WalletTopUp;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part 2: a WALLET_TOPUP order is paid with the shop's own payment methods and settled by PaymentSettlementService
 * like any order; the wallet is credited once, on confirmed settlement only, and nothing is ever provisioned.
 */
final class WalletTopUpTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const AMOUNT = 50000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
    }

    private function topUp(): WalletTopUp
    {
        return self::getContainer()->get(WalletTopUp::class);
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
        return (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]) ?: 0);
    }

    private function ledgerRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction');
    }

    /**
     * @return array{Order, Payment}
     */
    private function startTopUp(string $gatewayType, int $amount = self::AMOUNT): array
    {
        $order = $this->topUp()->createOrder($this->user, $amount);
        $start = $this->starter()->start($order, $this->methods[$gatewayType]);
        self::assertNotNull($start->payment, (string) $start->message);
        self::assertTrue($start->isReady(), (string) $start->message);

        return [$order, $start->payment];
    }

    /** The gateway (or, for card to card, the admin) confirms the payment. */
    private function confirm(Payment $payment, string $gatewayType, ?string $amountToman = null): \App\Payment\Application\Settlement\SettlementResult
    {
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        if (PaymentGatewayType::MANUAL_CARD === $gatewayType) {
            return $this->settlement()->approveByAdmin($payment, 1, null, 'test_admin');
        }

        return $this->settlement()->settle($payment, $this->gatewaySaysPaid($payment, $amountToman), 'test_webhook');
    }

    private function gatewaySaysPaid(Payment $payment, ?string $amountToman = null): VerifiedPayment
    {
        // What the gateway reports: the quote it was asked for (USD for NOWPayments), else the amount in Toman.
        [$amount, $currency] = null !== $payment->getExpectedAmount() && null === $amountToman
            ? [$payment->getExpectedAmount(), (string) $payment->getExpectedCurrency()]
            : [$amountToman ?? (string) $payment->getAmount(), 'IRT'];

        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-'.$payment->getId(), amount: $amount, currency: $currency));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gateways(): iterable
    {
        yield 'card to card' => [PaymentGatewayType::MANUAL_CARD];
        yield 'zibal' => [PaymentGatewayType::ZIBAL];
        yield 'custom api' => [PaymentGatewayType::CUSTOM_API];
        yield 'nowpayments' => [PaymentGatewayType::NOWPAYMENTS];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function onlineGateways(): iterable
    {
        yield 'zibal' => [PaymentGatewayType::ZIBAL];
        yield 'custom api' => [PaymentGatewayType::CUSTOM_API];
        yield 'nowpayments' => [PaymentGatewayType::NOWPAYMENTS];
    }

    // --- the order ------------------------------------------------------------------------------

    public function testATopUpOrderHasNoPlanAndWaitsForPayment(): void
    {
        $order = $this->topUp()->createOrder($this->user, self::AMOUNT);

        $row = $this->row('`order`', (int) $order->getId());
        self::assertSame(OrderType::WALLET_TOPUP, $row['type']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $row['status']);
        self::assertSame(self::AMOUNT, (int) $row['amount']);
        self::assertNull($row['plan_id']);
        self::assertNotNull($row['tracking_code']);
        self::assertFalse($order->hasPlan());
    }

    public function testEveryStorePaymentMethodIsOfferedForATopUp(): void
    {
        $order = $this->topUp()->createOrder($this->user, self::AMOUNT);

        $offered = array_map(static fn ($m): int => (int) $m->getId(), $this->starter()->methodsFor($order));
        sort($offered);
        $all = array_map(static fn ($m): int => (int) $m->getId(), array_values($this->methods));
        sort($all);

        self::assertSame($all, $offered);
    }

    public function testTheAmountMustBeWithinTheConfiguredRange(): void
    {
        $this->setting(WalletSettings::TOPUP_MIN, '20000');
        $this->setting(WalletSettings::TOPUP_MAX, '100000');

        foreach ([19999, 100001, 0, -5] as $amount) {
            try {
                $this->topUp()->createOrder($this->user, $amount);
                self::fail('top-up of '.$amount.' accepted');
            } catch (WalletException $e) {
                self::assertSame('topup_out_of_range', $e->error);
            }
        }
        $this->topUp()->createOrder($this->user, 20000);
        $this->topUp()->createOrder($this->user, 100000);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order` WHERE type = ?', [OrderType::WALLET_TOPUP]));
    }

    public function testNoTopUpWhileTheWalletIsDisabledOrFrozen(): void
    {
        $this->setting(WalletSettings::ENABLED, 'false');
        try {
            $this->topUp()->createOrder($this->user, self::AMOUNT);
            self::fail('top-up with the wallet disabled');
        } catch (WalletException $e) {
            self::assertSame('wallet_disabled', $e->error);
        }

        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(\App\Wallet\Application\WalletService::class)->setStatus($this->user, \App\Wallet\Domain\WalletStatus::FROZEN, \App\Wallet\Application\WalletActor::system());
        try {
            $this->topUp()->createOrder($this->user, self::AMOUNT);
            self::fail('top-up of a frozen wallet');
        } catch (WalletException $e) {
            self::assertSame('wallet_frozen', $e->error);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
    }

    // --- per gateway type -----------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function testAConfirmedPaymentCreditsTheWalletOnceAndProvisionsNothing(string $gatewayType): void
    {
        [$order, $payment] = $this->startTopUp($gatewayType);
        if (PaymentGatewayType::MANUAL_CARD === $gatewayType) {
            $this->em->getConnection()->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::SUBMITTED, $payment->getId()]);
        }

        $result = $this->confirm($payment, $gatewayType);

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertNull($result->vpnService);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(self::AMOUNT, $this->balance());
        $tx = $this->em->getRepository(WalletTransaction::class)->findOneBy([]);
        self::assertInstanceOf(WalletTransaction::class, $tx);
        self::assertSame(WalletTransactionType::TOPUP, $tx->getType());
        self::assertSame('topup:payment:'.$payment->getId(), $tx->getIdempotencyKey());
        self::assertSame('payment', $tx->getReferenceType());
        self::assertSame((string) $payment->getId(), $tx->getReferenceId());
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(0, $this->driver()->createCalls, 'no panel call for a top-up');
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, '50,000')), 'the customer is told the credited amount');

        // Repeated webhook / approval: nothing more.
        $again = $this->confirm($payment, $gatewayType);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $again->outcome);
        self::assertSame(1, $this->ledgerRows());
        self::assertSame(self::AMOUNT, $this->balance());
    }

    #[DataProvider('gateways')]
    public function testARejectedPaymentNeverCredits(string $gatewayType): void
    {
        [$order, $payment] = $this->startTopUp($gatewayType);
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();

        $result = PaymentGatewayType::MANUAL_CARD === $gatewayType
            ? $this->settlement()->reject($payment, 'no money arrived', 1, null, 'test_admin')
            : $this->settlement()->recordGatewayFailure($payment, 'test_verify');

        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->ledgerRows());
        self::assertSame(0, $this->balance());
        self::assertNotSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
    }

    #[DataProvider('gateways')]
    public function testAnExpiredPaymentNeverCredits(string $gatewayType): void
    {
        [$order, $payment] = $this->startTopUp($gatewayType);
        $this->expire($order);

        self::assertSame(OrderStatus::EXPIRED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->ledgerRows());
    }

    #[DataProvider('onlineGateways')]
    public function testAPaymentAfterExpiryIsParkedAndCreditedOnlyWhenAnAdminApproves(string $gatewayType): void
    {
        [$order, $payment] = $this->startTopUp($gatewayType);
        $this->expire($order);

        $late = $this->confirm($payment, $gatewayType);

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $late->outcome, $late->message);
        self::assertStringStartsWith(Payment::REVIEW_PREFIX_LATE_PAYMENT.'expiry', (string) $this->row('payment', (int) $payment->getId())['review_reason']);
        self::assertSame(0, $this->ledgerRows(), 'parked, not credited');

        $approved = $this->settlement()->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::CONFIRMED, $approved->outcome, $approved->message);
        self::assertSame(self::AMOUNT, $this->balance());
        self::assertSame(1, $this->ledgerRows());
    }

    #[DataProvider('onlineGateways')]
    public function testAPaymentAfterCancelIsParkedAndCreditedOnlyWhenAnAdminApproves(string $gatewayType): void
    {
        [$order, $payment] = $this->startTopUp($gatewayType);
        $cancel = $this->settlement()->cancelByCustomer($this->em->find(Order::class, $order->getId()) ?? throw new \LogicException());
        self::assertSame(SettlementOutcome::REJECTED, $cancel->outcome);

        $late = $this->confirm($payment, $gatewayType);

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $late->outcome, $late->message);
        self::assertStringStartsWith(Payment::REVIEW_PREFIX_LATE_PAYMENT.'cancel', (string) $this->row('payment', (int) $payment->getId())['review_reason']);
        self::assertSame(0, $this->ledgerRows());

        $this->settlement()->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 1, null, 'test_admin');
        $this->settlement()->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 1, null, 'test_admin');

        self::assertSame(self::AMOUNT, $this->balance());
        self::assertSame(1, $this->ledgerRows());
    }

    public function testARejectedLatePaymentIsNeverCredited(): void
    {
        [$order, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);
        $this->expire($order);
        $this->confirm($payment, PaymentGatewayType::ZIBAL);

        $this->settlement()->reject($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 'refunded at the bank', 1, null, 'test_admin', true);

        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->ledgerRows());
    }

    // --- amounts --------------------------------------------------------------------------------

    public function testAnUnderpaymentGoesToReviewAndCreditsNothing(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);

        $result = $this->confirm($payment, PaymentGatewayType::ZIBAL, '49000');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        self::assertSame(0, $this->ledgerRows());
    }

    public function testAnOverpaymentCreditsTheOrderedAmountAndAlertsTheAdmins(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);

        $result = $this->confirm($payment, PaymentGatewayType::ZIBAL, '60000');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);
        self::assertSame(PaymentSettlementService::FLAG_OVERPAID, $this->row('payment', (int) $payment->getId())['amount_flag']);
        self::assertSame(self::AMOUNT, $this->balance(), 'the ordered amount; the surplus is the admins\' call, as for any order');
    }

    /** Review finding I1: within the crypto tolerance, the wallet gets what was received, not the ordered amount. */
    public function testACryptoTopUpUnderpaidWithinToleranceCreditsWhatWasReceived(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::NOWPAYMENTS);
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        $expected = (string) Decimal::normalize((string) $payment->getExpectedAmount());
        $paid = Decimal::multiply($expected, '0.995'); // 0.5% short; the crypto default tolerates 1%

        $result = $this->settlement()->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'np-1', amount: $paid, currency: (string) $payment->getExpectedCurrency())), 'test_webhook');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(PaymentSettlementService::FLAG_UNDERPAID_TOLERATED, $this->row('payment', (int) $payment->getId())['amount_flag']);
        self::assertSame(49750, $this->balance(), 'floor(50000 * received / expected)');
        $note = (string) $this->em->getConnection()->fetchOne("SELECT note FROM wallet_transaction WHERE type = 'topup'");
        self::assertStringContainsString('received '.$paid.' '.$payment->getExpectedCurrency().' of '.$expected, $note);
        self::assertStringContainsString('credited 49750 of 50000 ordered', $note);
    }

    public function testAFiatTopUpUnderpaidWithinAConfiguredToleranceCreditsWhatWasReceived(): void
    {
        $gateway = $this->methods[PaymentGatewayType::ZIBAL]->getGateway();
        $gateway->setConfig(['underpayment_tolerance_percent' => '2'] + ($gateway->getConfig() ?? []));
        $this->em->flush();
        [, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);

        $result = $this->confirm($payment, PaymentGatewayType::ZIBAL, '49001');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(49001, $this->balance());
    }

    public function testAnAdminApprovingAnUnderpaidTopUpCreditsWhatWasReceived(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->confirm($payment, PaymentGatewayType::ZIBAL, '40000')->outcome);

        $approved = $this->settlement()->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::CONFIRMED, $approved->outcome, $approved->message);
        self::assertSame(40000, $this->balance(), 'what arrived; the rest is a manual adjustment if the admin wants it');
    }

    public function testAnApprovedCardToCardTopUpWithoutAReportedAmountCreditsTheOrderedAmount(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::MANUAL_CARD);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->confirm($payment, PaymentGatewayType::MANUAL_CARD)->outcome);
        self::assertSame(self::AMOUNT, $this->balance());
    }

    public function testASecondPaymentForAToppedUpOrderGoesToReviewWithoutASecondCredit(): void
    {
        [$order, $first] = $this->startTopUp(PaymentGatewayType::ZIBAL);
        $second = $this->createPayment($this->em->find(Order::class, $order->getId()) ?? throw new \LogicException(), $this->methods[PaymentGatewayType::ZIBAL]->getGateway(), PaymentStatus::PENDING);
        $this->confirm($first, PaymentGatewayType::ZIBAL);

        $duplicate = $this->confirm($second, PaymentGatewayType::ZIBAL, (string) self::AMOUNT);

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $duplicate->outcome);
        self::assertSame(1, $this->ledgerRows());
        self::assertSame(self::AMOUNT, $this->balance());
    }

    // --- no PROVISIONING_FAILED for a top-up --------------------------------------------------------

    public function testACreditThatFailsLeavesThePaymentRetryableNeverProvisioningFailed(): void
    {
        [$order, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);
        $listener = new class {
            public bool $fail = true;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($this->fail && $entity instanceof WalletTransaction) {
                        throw new \RuntimeException('Simulated database failure');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $failed = $this->confirm($payment, PaymentGatewayType::ZIBAL);

        self::assertSame(SettlementOutcome::ERROR, $failed->outcome, $failed->message);
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status'], 'nothing changed: the gateway/webhook retry settles it');
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(0, $this->ledgerRows());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE status = ?', [PaymentStatus::PROVISIONING_FAILED]));

        $listener->fail = false;
        $retry = $this->confirm($payment, PaymentGatewayType::ZIBAL);

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(self::AMOUNT, $this->balance());
    }

    // --- top-ups stay out of the purchase machinery ----------------------------------------------------

    public function testAConfirmedTopUpIsNotAStuckPayment(): void
    {
        [, $payment] = $this->startTopUp(PaymentGatewayType::ZIBAL);
        $this->confirm($payment, PaymentGatewayType::ZIBAL);
        $this->em->getConnection()->executeStatement('UPDATE payment SET confirmed_at = ? WHERE id = ?', [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $payment->getId()]);

        $stuck = self::getContainer()->get(\App\Payment\Application\Repair\StuckPaymentFinder::class)->find(new \DateTimeImmutable('-15 minutes'), 100);

        self::assertSame([], $stuck, 'a top-up has no service row and must never be moved to provisioning_failed');
    }

    public function testAnUnpaidTopUpIsNotTheBotsIncompleteOrder(): void
    {
        $this->topUp()->createOrder($this->user, self::AMOUNT);

        self::assertNull(self::getContainer()->get(\App\Shop\Application\IncompleteOrderService::class)->findActiveIncompleteForUser($this->user));
    }

    private function expire(Order $order): void
    {
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        $this->em->clear();
        self::getContainer()->get(IncompleteOrderExpiryService::class)->expire(false, 24);
        $this->em->clear();
    }
}
