<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentReturnUrl;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\ZibalGateway;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PaymentReturnPageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    /**
     * @return array{Order, Payment}
     */
    private function nowPayment(): array
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setCryptoPaymentId('5550001')->setGatewayTransactionId('5550001')->setExpectation('10', 'usd');
        $this->em->flush();

        return [$order, $payment];
    }

    private function apiSays(string $status, float $priceAmount = 10.0): void
    {
        $this->http->onJson('/v1/payment/5550001', ['payment_id' => 5550001, 'payment_status' => $status, 'price_amount' => $priceAmount, 'price_currency' => 'usd']);
    }

    /**
     * @param array<string, string> $query
     */
    private function open(Payment $payment, array $query = []): string
    {
        $this->client->request('GET', PaymentReturnUrl::path($payment), $query);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testEveryPaymentGetsAUniqueUnguessableToken(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $a = $this->createPayment($order);
        $b = $this->createPayment($order);

        self::assertMatchesRegularExpression('/^'.PaymentReturnUrl::TOKEN_PATTERN.'$/', (string) $a->getReturnToken());
        self::assertSame(43, strlen((string) $a->getReturnToken()), '32 random bytes, base64url');
        self::assertNotSame($a->getReturnToken(), $b->getReturnToken());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('UPDATE payment SET return_token = ? WHERE id = ?', [$a->getReturnToken(), $b->getId()]);
    }

    public function testPaidPaymentIsVerifiedSettledAndShown(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('finished');

        $this->open($payment);
        $content = $this->open($payment);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('https://panel.test/sub', $content);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertCount(1, $this->http->requests('/v1/payment/5550001'), 'a confirmed payment is not re-verified');
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
    }

    public function testAnAddressCustomersCannotReachIsNotShown(): void
    {
        // Panel set up without a public subscription address: the panel answers on 127.0.0.1.
        $this->driver()->subscriptionBase = 'http://127.0.0.1:2096/sub';
        $this->driver()->linkHost = '127.0.0.1';
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('finished');

        $content = $this->open($payment);

        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertStringNotContainsString('127.0.0.1', $content);
        self::assertStringNotContainsString('http-equiv="refresh"', $content, 'no redirect to it');
        self::assertStringContainsString('با پشتیبانی تماس بگیرید', $content);
        $customerChat = (string) $order->getUser()->getTelegramAccount()?->getTelegramId();
        $toCustomer = 0;
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true);
            if (is_array($body) && (string) ($body['chat_id'] ?? '') === $customerChat) {
                ++$toCustomer;
                self::assertStringNotContainsString('127.0.0.1', (string) ($body['text'] ?? ''), 'not in the Telegram confirmation either');
            }
        }
        self::assertGreaterThan(0, $toCustomer, 'the customer was notified');
    }

    public function testPendingPaymentShowsAutoRefreshingWaitingState(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('waiting');

        $content = $this->open($payment);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('در انتظار تایید پرداخت', $content);
        self::assertStringContainsString('<meta http-equiv="refresh" content="10">', $content);
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));

        // The provider confirms later: the next refresh settles and shows the service.
        $this->http->reset();
        $this->apiSays('finished');
        $content = $this->open($payment);
        self::assertStringContainsString('https://panel.test/sub', $content);
        self::assertStringNotContainsString('content="10"', $content);
    }

    public function testUnderpaymentShowsReviewState(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('finished', 5.0);

        self::assertStringContainsString('توسط پشتیبانی', $this->open($payment));
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testOnlyTheTokenIdentifiesAPayment(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('finished');
        $this->open($payment); // settle it

        $guesses = [
            ['/payment/success', ['NP_id' => '5550001']],
            ['/payment/success', ['order_id' => (string) $order->getId()]],
            ['/payment/success', ['payment_id' => (string) $payment->getId()]],
            ['/payment/return/'.str_repeat('A', 43), []],
            ['/payment/return/'.$payment->getId(), []],
        ];
        foreach ($guesses as [$url, $query]) {
            $this->client->request('GET', $url, $query);
            $label = $url.json_encode($query);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $label);
            self::assertStringNotContainsString('https://panel.test/sub', (string) $this->client->getResponse()->getContent(), $label);
        }
    }

    public function testNowPaymentsReturnUrlsCarryTheToken(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's', 'callback_base_url' => 'https://shop.test', 'payment_mode' => 'invoice', 'toman_per_usd' => 50000]);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $this->http->onJson('/v1/invoice', ['id' => 'inv-1', 'invoice_url' => 'https://nowpayments.test/inv-1']);

        self::getContainer()->get(NowPaymentsGateway::class)->createPayment($payment, $order);

        $requests = $this->http->requests('/v1/invoice');
        self::assertNotEmpty($requests);
        $sent = json_decode((string) $requests[0]['options']['body'], true);
        self::assertSame('https://shop.test/payment/return/'.$payment->getReturnToken(), $sent['success_url']);
        self::assertSame('https://shop.test/payment/return/'.$payment->getReturnToken().'/cancel', $sent['cancel_url']);
    }

    public function testZibalCallbackIsMatchedByTrackIdAndThenRedirectsToTheReturnPage(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => '888']);
        self::getContainer()->get(ZibalGateway::class)->createPayment($payment, $order);
        $this->em->flush();

        $sent = json_decode((string) $this->http->requests('/v1/request')[0]['options']['body'], true);
        self::assertSame('https://shop.test/payment/callback/zibal?rt='.$payment->getReturnToken(), $sent['callbackUrl']);

        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);
        $this->client->request('GET', '/payment/callback/zibal', ['rt' => (string) $payment->getReturnToken(), 'trackId' => '888', 'success' => '1', 'status' => '2']);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('/payment/return/'.$payment->getReturnToken(), $this->client->getResponse()->headers->get('Location'));
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testCallbackTokenNeverSelectsThePaymentToSettle(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('999')->setAuthority('999');
        $this->em->flush();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);

        // Right token but no trackId: the callback itself settles nothing (the token is not a reference).
        $this->client->request('GET', '/payment/callback/zibal', ['rt' => (string) $payment->getReturnToken(), 'success' => '1']);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->http->requests('/v1/verify'));
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
    }
}
