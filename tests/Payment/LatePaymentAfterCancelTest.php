<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * pending -> cancelled by the customer -> paid anyway at the gateway: the money is never ignored.
 */
final class LatePaymentAfterCancelTest extends IntegrationTestCase
{
    private const TELEGRAM_ID = '555002';
    private const IPN_SECRET = 'ipn-secret-for-tests';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    private function cancel(Order $order): void
    {
        $result = self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($order);
        self::assertSame(SettlementOutcome::REJECTED, $result->outcome, $result->message);
        $this->em->clear();
    }

    /**
     * @return array{Order, Payment}
     */
    private function cancelledZibalPayment(): array
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('901')->setAuthority('901');
        $this->em->flush();
        $this->cancel($order);
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);

        return [$order, $payment];
    }

    private function assertParkedAsPaidAfterCancel(Order $order, Payment $payment): void
    {
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertStringStartsWith('paid after cancel', (string) $row['review_reason']);
        self::assertSame(0, $this->countServicesForOrder($order));
        $alerts = array_filter($this->http->requests('/sendMessage'), static fn (array $r): bool => str_contains((string) ($r['options']['body'] ?? ''), 'admin_confirm_payment:'.$payment->getId()));
        self::assertNotEmpty($alerts, 'admins alerted with an Approve button');
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'پس از لغو یا پایان مهلت')), 'customer told it is under review, not an amount problem');
    }

    public function testLatePaidCallback(): void
    {
        [$order, $payment] = $this->cancelledZibalPayment();

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => '901', 'success' => '1', 'status' => '2']);

        $this->assertParkedAsPaidAfterCancel($order, $payment);
    }

    public function testLatePaidWebhook(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => self::IPN_SECRET]);
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setCryptoPaymentId('np_9')->setGatewayTransactionId('np_9')->setExpectation('10', 'usd');
        $this->em->flush();
        $this->cancel($order);
        $this->http->onJson('/v1/payment/np_9', ['payment_id' => 'np_9', 'payment_status' => 'finished', 'price_amount' => 10, 'price_currency' => 'usd']);

        $body = ['payment_id' => 'np_9', 'payment_status' => 'finished'];
        ksort($body);
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->client->request('POST', '/payment/webhook/nowpayments', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NOWPAYMENTS_SIG' => hash_hmac('sha512', $json, self::IPN_SECRET)], $json);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertParkedAsPaidAfterCancel($order, $payment);
    }

    public function testLatePaidReturnPage(): void
    {
        [$order, $payment] = $this->cancelledZibalPayment();
        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException('payment missing');

        $this->client->request('GET', PaymentReturnUrl::path($reloaded));

        $this->assertParkedAsPaidAfterCancel($order, $payment);
        self::assertStringContainsString('پس از لغو یا پایان مهلت سفارش دریافت شد', (string) $this->client->getResponse()->getContent());
    }

    public function testReturnPageOfACancelledUnpaidPaymentSaysCancelledNotFailed(): void
    {
        [, $payment] = $this->cancelledZibalPayment();
        $this->http->reset();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 202, 'message' => 'not paid']);
        $reloaded = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException('payment missing');

        $this->client->request('GET', PaymentReturnUrl::path($reloaded));

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('لغو شده یا مهلت آن تمام شده', $content);
        self::assertStringNotContainsString('ناموفق', $content);
    }

    public function testLatePaidBotCheck(): void
    {
        [$order, $payment] = $this->cancelledZibalPayment();

        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 1_000_000),
                'from' => ['id' => (int) self::TELEGRAM_ID, 'first_name' => 'T', 'username' => 'customer'],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) self::TELEGRAM_ID, 'type' => 'private']],
                'data' => 'payment_check:'.$payment->getId(),
            ],
        ]);

        $this->assertParkedAsPaidAfterCancel($order, $payment);
        $replies = array_merge($this->http->telegramTexts(), array_map(static fn (array $r): string => (string) ($r['options']['body'] ?? ''), $this->http->requests('/answerCallbackQuery')));
        self::assertEmpty(array_filter($replies, static fn (string $t): bool => str_contains($t, 'در حال پیگیری است') || str_contains($t, 'مطابقت ندارد')), 'no misleading "already registered" / "amount mismatch" reply');
    }

    public function testAdminRejectedAndGatewayFailedPaymentsStayRejected(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $settlement = self::getContainer()->get(PaymentSettlementService::class);
        $orders = [];
        foreach (['911' => 'admin', '912' => 'gateway'] as $trackId => $how) {
            $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
            $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
            $payment->setGatewayTransactionId((string) $trackId)->setAuthority((string) $trackId);
            $this->em->flush();
            'admin' === $how ? $settlement->reject($payment, 'no', 1, null, 'test') : $settlement->recordGatewayFailure($payment, 'test');
            $orders[$trackId] = $payment;
        }
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);

        foreach ($orders as $trackId => $payment) {
            $this->client->request('GET', '/payment/callback/zibal', ['trackId' => (string) $trackId, 'success' => '1', 'status' => '2']);
            self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status'], (string) $trackId);
        }
    }

    #[Group('subprocess')]
    public function testCancelDuringAnInProgressSettlementNeverRewritesIt(): void
    {
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $php = $this->phpForSubprocesses();
        $root = dirname(__DIR__, 2);

        // Settlement holds the order lock while the panel is slow (2.5 s).
        $settle = new Process([$php, 'tests/Support/bin/settle-payment.php', (string) $payment->getId(), '150000'], $root, ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '2500'], null, 60);
        $settle->start();
        usleep(1_200_000);
        $cancel = new Process([$php, 'tests/Support/bin/cancel-order.php', (string) $order->getId()], $root, ['APP_ENV' => 'test'], null, 60);
        $cancel->start();
        $settle->wait();
        $cancel->wait();

        self::assertTrue($settle->isSuccessful(), $settle->getErrorOutput());
        self::assertTrue($cancel->isSuccessful(), $cancel->getErrorOutput());
        self::assertSame(SettlementOutcome::CONFIRMED->value, trim($settle->getOutput()));
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED->value, trim($cancel->getOutput()), 'the cancel waited for the lock and saw the confirmation');
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    #[Group('subprocess')]
    public function testCancelAndSettlementStartedTogetherEndInAConsistentState(): void
    {
        $php = $this->phpForSubprocesses();
        $root = dirname(__DIR__, 2);

        for ($i = 0; $i < 3; ++$i) {
            $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
            $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
            $settle = new Process([$php, 'tests/Support/bin/settle-payment.php', (string) $payment->getId(), '150000'], $root, ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '300'], null, 60);
            $cancel = new Process([$php, 'tests/Support/bin/cancel-order.php', (string) $order->getId()], $root, ['APP_ENV' => 'test'], null, 60);
            $settle->start();
            $cancel->start();
            $settle->wait();
            $cancel->wait();
            self::assertTrue($settle->isSuccessful() && $cancel->isSuccessful(), $settle->getErrorOutput().$cancel->getErrorOutput());

            $paymentRow = $this->row('payment', (int) $payment->getId());
            $orderStatus = $this->row('`order`', (int) $order->getId())['status'];
            if (PaymentStatus::CONFIRMED === $paymentRow['status']) {
                // Settlement won: the cancel must not have touched anything.
                self::assertSame(OrderStatus::PROVISIONED, $orderStatus);
                self::assertSame(1, $this->countServicesForOrder($order));
            } else {
                // Cancel won: the settlement parked the money for review instead of losing it.
                self::assertSame(PaymentStatus::NEEDS_REVIEW, $paymentRow['status']);
                self::assertStringStartsWith('paid after cancel', (string) $paymentRow['review_reason']);
                self::assertSame(OrderStatus::CANCELLED, $orderStatus);
                self::assertSame(0, $this->countServicesForOrder($order));
            }
        }
    }

    private function phpForSubprocesses(): string
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }
}
