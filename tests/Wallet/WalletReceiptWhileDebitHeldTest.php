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
use App\Shop\Application\Checkout\ReceiptSubmission;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Application\WalletReconciler;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fourth review, item 4: a card-to-card receipt is refused (bot and Mini App) while a wallet payment of the order holds
 * its debit (pending, in review, or waiting for a retry): the customer would pay twice. And a receipt never moves an
 * order out of provisioning_failed.
 */
final class WalletReceiptWhileDebitHeldTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const PRICE = 150000;
    private const FUNDED = 200000;
    private const HELD_FA = 'برای این سفارش پرداخت از کیف پول ثبت شده است';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
        self::getContainer()->get(WalletService::class)->credit($this->user, self::FUNDED, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function receipts(): ReceiptSubmission
    {
        return self::getContainer()->get(ReceiptSubmission::class);
    }

    private function fresh(Order $order): Order
    {
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $this->em->refresh($order);

        return $order;
    }

    private function payment(int $id): Payment
    {
        $payment = $this->em->find(Payment::class, $id) ?? throw new \LogicException();
        $this->em->refresh($payment);

        return $payment;
    }

    private function method(string $type): StorePaymentMethod
    {
        return $this->em->find(StorePaymentMethod::class, $this->methods[$type]->getId()) ?? throw new \LogicException();
    }

    /** @return array{Order, Payment} [order, its pending card payment] */
    private function orderWithCardPayment(): array
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $card = self::getContainer()->get(PaymentStarter::class)->start($order, $this->method(PaymentGatewayType::MANUAL_CARD))->payment ?? throw new \LogicException();

        return [$order, $card];
    }

    /** @return array{Order, Payment, Payment} [order, pending card payment, wallet payment in provisioning_failed] */
    private function walletPaymentFailedAfterCard(): array
    {
        [$order, $card] = $this->orderWithCardPayment();
        $this->driver()->failNext(1);
        $result = self::getContainer()->get(WalletPayment::class)->pay($this->fresh($order));
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);

        return [$order, $card, $result->payment ?? throw new \LogicException()];
    }

    private function assertNothingChanged(Order $order, Payment $card, string $orderStatus): void
    {
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $card->getId())['status']);
        self::assertNull($this->row('payment', (int) $card->getId())['tracking_code']);
        self::assertSame($orderStatus, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(self::FUNDED - self::PRICE, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testNoReceiptWhileTheWalletPaymentWaitsForARetry(): void
    {
        [$order, $card] = $this->walletPaymentFailedAfterCard();

        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->check($this->payment((int) $card->getId()), $this->user));
        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->submitTrackingCode($this->payment((int) $card->getId()), 'TRACK-1'));
        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->submitPhoto($this->payment((int) $card->getId()), 'AgAD-photo'));
        $this->assertNothingChanged($order, $card, OrderStatus::PROVISIONING_FAILED);
    }

    public function testNoReceiptWhileAWalletPaymentIsDebitedAndNotSettled(): void
    {
        [$order, $card] = $this->orderWithCardPayment();
        self::getContainer()->get(WalletPayment::class)->open($this->fresh($order));

        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->check($this->payment((int) $card->getId()), $this->user));
        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->submitTrackingCode($this->payment((int) $card->getId()), 'TRACK-1'));
        $this->assertNothingChanged($order, $card, OrderStatus::WAITING_PAYMENT);
    }

    /** The check ran before the wallet payment was opened: the submission itself re-checks under the order lock. */
    public function testTheSubmissionChecksAgainUnderTheOrderLock(): void
    {
        [$order, $card] = $this->orderWithCardPayment();
        self::assertSame(ReceiptSubmission::READY, $this->receipts()->check($this->payment((int) $card->getId()), $this->user));
        self::getContainer()->get(WalletPayment::class)->open($this->fresh($order));

        self::assertSame(ReceiptSubmission::WALLET_PAYMENT_HELD, $this->receipts()->submitTrackingCode($this->payment((int) $card->getId()), 'TRACK-1'));
        $this->assertNothingChanged($order, $card, OrderStatus::WAITING_PAYMENT);
    }

    public function testTheBotRefusesTheReceiptWithAClearMessage(): void
    {
        [$order, $card] = $this->walletPaymentFailedAfterCard();

        $this->pressButton(self::CUSTOMER, 'payment_submit_receipt:'.$card->getId());

        self::assertTrue($this->sentTextContains(self::HELD_FA), implode("\n", $this->sentTexts()));
        $this->assertNothingChanged($order, $card, OrderStatus::PROVISIONING_FAILED);
    }

    public function testTheMiniAppRefusesTheReceiptWithAClearMessage(): void
    {
        [$order, $card] = $this->walletPaymentFailedAfterCard();
        $session = $this->authenticate(self::CUSTOMER);

        foreach (['fa' => self::HELD_FA, 'en' => 'This order already has a payment from your wallet'] as $lang => $text) {
            $this->client->request('POST', '/app/api/payments/'.$card->getId().'/receipt?lang='.$lang, [], ['receipt' => new UploadedFile($this->png(), 'receipt.png', 'image/png', null, true)], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$session['token'], 'HTTP_X_CSRF_TOKEN' => $session['csrf'],
            ]);
            $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

            self::assertSame(409, $this->httpStatus());
            self::assertSame('receipt_wallet_payment_held', $answer['error'] ?? null);
            self::assertStringContainsString($text, (string) ($answer['message'] ?? ''), $lang);
        }
        self::assertSame([], $this->http->requests('/sendPhoto'), 'nothing was uploaded to Telegram');
        $this->assertNothingChanged($order, $card, OrderStatus::PROVISIONING_FAILED);
    }

    /**
     * Another (gateway) payment of the order is paid and waits for a retry: a receipt for the old card payment is
     * recorded for the admin to look at, but the order stays in provisioning_failed.
     */
    public function testAReceiptNeverMovesTheOrderOutOfProvisioningFailed(): void
    {
        $order = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $zibal = self::getContainer()->get(PaymentStarter::class)->start($order, $this->method(PaymentGatewayType::ZIBAL))->payment ?? throw new \LogicException();
        $this->driver()->failNext(1);
        $failed = self::getContainer()->get(PaymentSettlementService::class)->settle($this->payment((int) $zibal->getId()), VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'z-1', amount: (string) self::PRICE, currency: 'IRT')), 'test_webhook');
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome, $failed->message);
        // An older card-to-card payment of the same order, still pending.
        $card = $this->createPayment($this->fresh($order), $this->createGateway(PaymentGatewayType::MANUAL_CARD), PaymentStatus::PENDING);

        $this->receipts()->expectReceipt($this->payment((int) $card->getId()));
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(ReceiptSubmission::READY, $this->receipts()->submitTrackingCode($this->payment((int) $card->getId()), 'TRACK-2'));

        self::assertSame(PaymentStatus::SUBMITTED, $this->row('payment', (int) $card->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30) ?: throw new \LogicException('GD is needed');
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'-receipt.png';
        file_put_contents($path, (string) ob_get_clean());
        $this->tempFiles[] = $path;

        return $path;
    }
}
