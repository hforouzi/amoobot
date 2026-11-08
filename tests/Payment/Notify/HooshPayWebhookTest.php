<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use Amoobot\Plugin\HooshPay\HooshPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Setting;
use App\Payment\Domain\PaymentStatus;
use App\Payment\UI\HooshPayWebhookController;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\HooshPayFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * HooshPay's webhook (HooshPayWebhookController): only a correctly signed request of an enabled gateway reaches the
 * lookup; only HooshPay's verify, matching uid, order_id and our amount, reaches settle(); settle() credits once.
 */
final class HooshPayWebhookTest extends IntegrationTestCase
{
    use HooshPayFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installHooshPay();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        self::getContainer()->get(RecordingLogger::class)->records = [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{int, string}
     */
    private function webhook(array $body, ?string $signature = 'sign', ?string $raw = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ('sign' === $signature) {
            $signature = HooshPayGatewayPlugin::signature($body, self::HOOSH_SECRET);
        }
        if (null !== $signature) {
            $server['HTTP_X_HOOSHPAY_SIGNATURE'] = $signature;
        }
        $this->client->request('POST', '/payment/webhook/hooshpay', server: $server, content: $raw ?? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), (string) (json_decode((string) $response->getContent(), true)['message'] ?? '')];
    }

    private function paymentStatus(Payment $payment): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]);
    }

    private function logs(): RecordingLogger
    {
        return self::getContainer()->get(RecordingLogger::class);
    }

    private function walletTopUp(int $amount = self::HOOSH_AMOUNT): Order
    {
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true')->setType('boolean'));
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount($amount)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function balance(Order $order): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$order->getUser()->getId()]);
    }

    public function testASignedWebhookIsVerifiedAndSettled(): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment)]);

        [$status, $code] = $this->webhook(self::hooshWebhookBody());

        self::assertSame(200, $status);
        self::assertSame('confirmed', $code);
        $calls = $this->hooshPayCalls();
        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/'.self::HOOSH_UID.'/verify', $calls[0]['url']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->paymentStatus($payment));
        self::assertSame(HooshPayWebhookController::SOURCE, $this->em->getConnection()->fetchOne('SELECT confirmation_source FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
        self::assertStringNotContainsString('6037991234567890', (string) $this->em->getConnection()->fetchOne('SELECT verify_payload FROM payment WHERE id = ?', [$payment->getId()]), 'the card is never stored');
    }

    public function testADuplicateWebhookCreditsOnce(): void
    {
        $order = $this->walletTopUp();
        $payment = $this->hooshPayPayment($order);
        $this->hooshPayAnswers([self::hooshVerified($payment), self::hooshVerified($payment)]);

        $answers = [];
        for ($i = 0; $i < 3; ++$i) {
            $answers[] = $this->webhook(self::hooshWebhookBody());
        }

        self::assertSame([[200, 'confirmed'], [200, 'already_confirmed'], [200, 'already_confirmed']], $answers);

        self::assertCount(1, $this->hooshPayCalls(), 'a settled payment is not verified again');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
        self::assertSame(self::HOOSH_AMOUNT, $this->balance($order));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function feeModes(): iterable
    {
        yield 'seller' => ['seller', self::HOOSH_AMOUNT + 13];
        yield 'buyer' => ['buyer', self::HOOSH_AMOUNT + 30_000 + 17];
        yield 'split' => ['split', self::HOOSH_AMOUNT + 15_000 + 21];
    }

    #[DataProvider('feeModes')]
    public function testTheWalletIsCreditedOurAmountNeverThePayableAmount(string $feeMode, int $payable): void
    {
        $order = $this->walletTopUp();
        $payment = $this->hooshPayPayment($order, $this->hooshPayGateway(['fee_mode' => $feeMode]), $payable);
        $this->hooshPayAnswers([self::hooshVerified($payment, ['fee_mode' => $feeMode, 'payable_amount' => $payable, 'merchant_credit' => self::HOOSH_AMOUNT - 30_000])]);

        self::assertSame([200, 'confirmed'], $this->webhook(self::hooshWebhookBody(['fee_mode' => $feeMode, 'payable_amount' => $payable])));

        self::assertSame(self::HOOSH_AMOUNT, $this->balance($order));
        self::assertSame(self::HOOSH_AMOUNT, (int) $this->em->getConnection()->fetchOne('SELECT verified_amount FROM payment WHERE id = ?', [$payment->getId()]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthenticated(): iterable
    {
        yield 'missing header' => ['missing'];
        yield 'wrong secret' => ['wrong_secret'];
        yield 'tampered amount' => ['tampered'];
        yield 'garbage header' => ['garbage'];
        yield 'not json' => ['not_json'];
    }

    #[DataProvider('unauthenticated')]
    public function testAnUnauthenticatedWebhookIsRejectedAndChangesNothing(string $case): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment)]);
        $body = self::hooshWebhookBody();
        $signature = HooshPayGatewayPlugin::signature($body, self::HOOSH_SECRET);
        $raw = null;
        switch ($case) {
            case 'missing': $signature = null; break;
            case 'wrong_secret': $signature = HooshPayGatewayPlugin::signature($body, 'not-the-secret'); break;
            case 'tampered': $body['amount'] = 999_999_999; break;
            case 'garbage': $signature = 'abc'; break;
            case 'not_json': $raw = 'invoice='.self::HOOSH_UID; break;
        }

        [$status, $code] = $this->webhook($body, $signature, $raw);

        self::assertSame(401, $status);
        self::assertSame('unauthorized', $code);
        self::assertSame([], $this->hooshPayCalls(), 'no verify for an unauthenticated request');
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
        $log = $this->logs()->text();
        self::assertStringContainsString('HooshPay webhook rejected.', $log);
        self::assertStringNotContainsString(self::HOOSH_SECRET, $log);
        if (strlen((string) $signature) >= 16) {
            self::assertStringNotContainsString((string) $signature, $log, 'the signature is never logged');
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, string}>
     */
    public static function verifyMismatches(): iterable
    {
        yield 'amount mismatch' => [['amount' => self::HOOSH_AMOUNT - 1000], true, 'amount_mismatch'];
        yield 'payable amount reported as amount' => [['amount' => self::HOOSH_AMOUNT + 17], true, 'amount_mismatch'];
        yield 'order_id mismatch' => [['order_id' => 'SOMEONE-ELSE'], true, 'order_id_mismatch'];
        yield 'uid mismatch' => [['uid' => 'inv_OTHER'], true, 'uid_mismatch'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('verifyMismatches')]
    public function testAVerifyMismatchIsLoggedAsASecurityWarningAndNotSettled(array $data, bool $paid, string $reason): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment, $data, $paid)]);

        [$status, $code] = $this->webhook(self::hooshWebhookBody());

        self::assertSame(200, $status, 'a retry would not change the answer');
        self::assertSame('mismatch', $code);
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
        $warnings = array_values(array_filter($this->logs()->records, static fn (array $r): bool => str_starts_with($r['message'], 'Security: HooshPay')));
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertContains($reason, $warnings[0]['context']['reasons']);
        self::assertStringNotContainsString(self::HOOSH_KEY, $this->logs()->text());
    }

    public function testPaidFalseLeavesThePaymentPending(): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment, [], false)]);

        self::assertSame([200, 'pending'], $this->webhook(self::hooshWebhookBody()));
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testAVerifyOutageAsksHooshPayToRetry(): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([['status' => 503, 'body' => []]]);

        self::assertSame([503, 'verification_unavailable'], $this->webhook(self::hooshWebhookBody()));
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testAnUnknownInvoiceIsAnswered200WithNoAction(): void
    {
        $payment = $this->hooshPayPayment();

        self::assertSame([200, 'payment_not_found'], $this->webhook(self::hooshWebhookBody(['invoice' => 'inv_UNKNOWN'])));
        self::assertSame([], $this->hooshPayCalls());
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
        self::assertStringContainsString('HooshPay webhook: unknown invoice, ignored.', $this->logs()->text());
    }

    public function testAnInvoiceOfAnotherGatewayIsUnknown(): void
    {
        // The same uid stored on a payment of another gateway: never found through HooshPay's webhook.
        $other = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), $this->createGateway(), PaymentStatus::PENDING)->setGatewayTransactionId(self::HOOSH_UID);
        $this->em->flush();
        $this->hooshPayGateway();

        self::assertSame([200, 'payment_not_found'], $this->webhook(self::hooshWebhookBody()));
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($other));
    }

    public function testADisabledGatewayRejectsTheWebhook(): void
    {
        $payment = $this->hooshPayPayment(gateway: $this->hooshPayGateway(['enabled' => false]));
        $this->hooshPayAnswers([self::hooshVerified($payment)]);

        [$status, $code] = $this->webhook(self::hooshWebhookBody());

        self::assertSame(400, $status);
        self::assertSame(HooshPayGatewayPlugin::WEBHOOK_DISABLED, $code);
        self::assertSame([], $this->hooshPayCalls());
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testADisabledPluginOrNoGatewayRejectsTheWebhook(): void
    {
        self::assertSame([404, 'gateway_not_found'], $this->webhook(self::hooshWebhookBody()), 'no HooshPay gateway at all');

        $payment = $this->hooshPayPayment();
        $this->em->getConnection()->executeStatement("UPDATE plugin SET status = 'disabled' WHERE code = 'hooshpay'");
        $this->em->clear();

        self::assertSame([404, 'gateway_not_found'], $this->webhook(self::hooshWebhookBody()));
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testTheGenericPluginRoutesAreClosedForHooshPay(): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment)]);
        $body = self::hooshWebhookBody();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HOOSHPAY_SIGNATURE' => HooshPayGatewayPlugin::signature($body, self::HOOSH_SECRET)];

        foreach (['/payment/webhook/plugin/hooshpay', '/payment/webhook/plugin/HooshPay', '/payment/callback/plugin/hooshpay?transaction_id='.self::HOOSH_UID] as $path) {
            $this->client->request('POST', $path, server: $server, content: (string) json_encode($body));
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $path);
        }
        self::assertSame([], $this->hooshPayCalls());
        self::assertSame(PaymentStatus::PENDING, $this->paymentStatus($payment));
    }

    public function testNoSecretOrCardReachesTheLogs(): void
    {
        $payment = $this->hooshPayPayment();
        $this->hooshPayAnswers([self::hooshVerified($payment, ['amount' => 1]), ['transport' => 'Idle timeout reached for "https://hooshpay.xyz/api/v1/invoices/'.self::HOOSH_UID.'/verify" key '.self::HOOSH_KEY]]);

        $this->webhook(self::hooshWebhookBody());
        $this->webhook(self::hooshWebhookBody(), HooshPayGatewayPlugin::signature(self::hooshWebhookBody(), 'wrong'));
        $this->webhook(self::hooshWebhookBody());

        $log = $this->logs()->text();
        self::assertNotSame('', $log);
        foreach ([self::HOOSH_KEY, self::HOOSH_SECRET, '6037991234567890', 'Card Holder', HooshPayGatewayPlugin::signature(self::hooshWebhookBody(), self::HOOSH_SECRET)] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }
}
