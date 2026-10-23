<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\MiniAppSession;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Entity\TelegramAccount;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Security\MiniAppUser;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStart;
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
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletTransactionType;

/**
 * Third review, blocker 1: the customer started a Zibal / card-to-card payment, then paid from the wallet; the panel
 * failed, so the wallet payment is provisioning_failed with its debit held (for an admin retry). Then the FIRST payment
 * completed and provisioned the order: the customer had paid twice and the debit stayed held.
 *
 * Now the payment that pays the order releases every other wallet payment of it, whatever its status, in the same
 * transaction; and while a wallet debit is held no other payment is started or reused for the order.
 */
final class WalletProvisioningFailedPaidOtherwiseTest extends IntegrationTestCase
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

    /** A failed settlement clears the entity manager: the fixtures' method is reloaded. */
    private function method(string $type): StorePaymentMethod
    {
        return $this->em->find(StorePaymentMethod::class, $this->methods[$type]->getId()) ?? throw new \LogicException();
    }

    /**
     * A payment of $method started first, then the wallet payment, whose panel call fails.
     *
     * @return array{Order, Payment, Payment} [order, first payment, wallet payment (provisioning_failed)]
     */
    private function walletPaymentFailedAfter(string $method): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $first = self::getContainer()->get(PaymentStarter::class)->start($order, $this->methods[$method])->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $result = self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'the debit is held for an admin retry');

        return [$order, $first, $result->payment ?? throw new \LogicException()];
    }

    private function assertReleasedAndPaidOnce(Order $order, Payment $wallet): void
    {
        $row = $this->row('payment', (int) $wallet->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_ORDER_PAID_OTHERWISE, $row['confirmation_source']);
        self::assertSame(self::FUNDED, $this->balance(), 'the wallet debit came back: the customer paid once');
        self::assertNotNull(self::getContainer()->get(WalletService::class)->findTransaction(WalletKeys::purchaseReversal((int) $wallet->getId())));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM payment WHERE order_id = ? AND status = 'confirmed'", [$order->getId()]));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /** After the release: neither the admin's retry nor the customer's wallet button takes or provisions anything. */
    private function assertRetryAfterReleaseChangesNothing(Order $order, Payment $wallet): void
    {
        $retry = $this->settlement()->retryProvisioning($this->freshPayment((int) $wallet->getId()), 1, null, 'test_admin');
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $retry->outcome, $retry->message);
        $approve = $this->settlement()->approveByAdmin($this->freshPayment((int) $wallet->getId()), 1, null, 'test_admin');
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $approve->outcome, $approve->message);
        try {
            self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));
            self::fail('the paid order cannot be paid from the wallet again');
        } catch (WalletException $e) {
            self::assertSame('order_not_payable', $e->error);
        }
        self::assertSame(self::FUNDED, $this->balance());
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'reversal'"));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testZibalCompletedAfterTheWalletPaymentFailedProvisioningReleasesTheDebit(): void
    {
        [$order, $zibal, $wallet] = $this->walletPaymentFailedAfter(PaymentGatewayType::ZIBAL);
        $before = count($this->http->telegramTexts());

        $result = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        $this->assertReleasedAndPaidOnce($order, $wallet);
        $texts = array_slice($this->http->telegramTexts(), $before);
        self::assertCount(1, array_filter($texts, static fn (string $t): bool => str_contains($t, '150,000') && str_contains($t, 'کیف پول')), 'the customer is told once that the amount came back');

        // A duplicate webhook of the paying payment changes nothing either.
        $again = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $again->outcome);
        $this->assertRetryAfterReleaseChangesNothing($order, $wallet);
    }

    public function testCardToCardApprovedAfterTheWalletPaymentFailedProvisioningReleasesTheDebit(): void
    {
        [$order, $card, $wallet] = $this->walletPaymentFailedAfter(PaymentGatewayType::MANUAL_CARD);
        // The card payment is still pending: a receipt for it can be sent (the Mini App checks the payment, not the order).
        self::assertSame(ReceiptSubmission::READY, self::getContainer()->get(ReceiptSubmission::class)->check($this->freshPayment((int) $card->getId()), $this->user));
        self::getContainer()->get(ReceiptSubmission::class)->submitTrackingCode($this->freshPayment((int) $card->getId()), 'TRACK-1');

        $result = $this->settlement()->approveByAdmin($this->freshPayment((int) $card->getId()), 1, null, 'test_admin');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        $this->assertReleasedAndPaidOnce($order, $wallet);
        $this->assertRetryAfterReleaseChangesNothing($order, $wallet);
    }

    /** The admin retries the wallet payment first: it pays the order, and the gateway payment is then a duplicate. */
    public function testRetryingTheWalletPaymentFirstMakesTheGatewayPaymentADuplicate(): void
    {
        [$order, $zibal, $wallet] = $this->walletPaymentFailedAfter(PaymentGatewayType::ZIBAL);

        $retry = $this->settlement()->retryProvisioning($this->freshPayment((int) $wallet->getId()), 1, null, 'test_admin');
        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        $late = $this->settlement()->settle($this->freshPayment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-2', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $late->outcome, 'parked for a refund, never provisioned twice');
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(self::FUNDED - self::PRICE, $this->balance(), 'the wallet paid the order: its debit stays');
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testNoPaymentIsStartedOrReusedWhileTheWalletDebitIsHeld(): void
    {
        [$order, $zibal, $wallet] = $this->walletPaymentFailedAfter(PaymentGatewayType::ZIBAL);
        $zibalRow = $this->row('payment', (int) $zibal->getId());

        foreach ([PaymentGatewayType::ZIBAL, PaymentGatewayType::MANUAL_CARD, PaymentGatewayType::NOWPAYMENTS] as $type) {
            $start = self::getContainer()->get(PaymentStarter::class)->start($this->fresh($order), $this->method($type));
            self::assertSame(PaymentStart::KIND_WALLET_PAYMENT_HELD, $start->kind, $type);
            self::assertNull($start->payment);
        }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$order->getId()]), 'nothing new started');
        self::assertSame($zibalRow, $this->row('payment', (int) $zibal->getId()), 'the earlier gateway payment was not reused');
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $wallet->getId())['status']);
    }

    /**
     * The bot and the Mini App only offer payment methods for an order waiting for payment; one whose wallet payment
     * holds its debit (here put back to waiting, as an admin's manual edit would) is still refused with a clear message.
     */
    public function testTheBotAndTheMiniAppTellTheCustomerNotToPayAgain(): void
    {
        [$order, , $wallet] = $this->walletPaymentFailedAfter(PaymentGatewayType::ZIBAL);
        $this->em->getConnection()->executeStatement("UPDATE `order` SET status = 'waiting_payment' WHERE id = ?", [$order->getId()]);
        $this->em->clear();
        $payments = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment');

        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => self::CUSTOMER]) ?? throw new \LogicException();
        $miniAppUser = new MiniAppUser($account, new MiniAppSession(str_repeat('0', 64), self::CUSTOMER, str_repeat('0', 64), new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour')), null);
        try {
            self::getContainer()->get(MiniAppCheckout::class)->pay($miniAppUser, (int) $order->getId(), (int) $this->methods[PaymentGatewayType::ZIBAL]->getId());
            self::fail('expected a refusal');
        } catch (CheckoutFailure $e) {
            self::assertSame('wallet_payment_held', $e->error);
            self::assertSame(409, $e->status);
        }

        self::getContainer()->get(TelegramUpdateHandler::class)->handle(['update_id' => 1, 'callback_query' => [
            'id' => 'cb-held', 'from' => ['id' => (int) self::CUSTOMER, 'first_name' => 'T'],
            'message' => ['message_id' => 1, 'chat' => ['id' => (int) self::CUSTOMER, 'type' => 'private']],
            'data' => sprintf('select_store_payment_method:%d:%d', $order->getId(), $this->methods[PaymentGatewayType::ZIBAL]->getId()),
        ]]);
        $answers = array_map(static function (array $request): string {
            $body = $request['options']['body'] ?? $request['options']['json'] ?? [];
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    parse_str($body, $decoded);
                }
                $body = $decoded;
            }

            return is_array($body) ? (string) ($body['text'] ?? '') : '';
        }, $this->http->requests('/answerCallbackQuery'));
        self::assertContains(TelegramUpdateHandler::WALLET_PAYMENT_HELD_TEXT, $answers);

        self::assertSame($payments, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'), 'nothing was started');
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $wallet->getId())['status']);
        self::assertSame(self::FUNDED - self::PRICE, $this->balance());
    }
}
