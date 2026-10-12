<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class NowPaymentsWebhookTest extends IntegrationTestCase
{
    private const SECRET = 'test-ipn-secret';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Same kernel/container as the fixtures and the HTTP mock.
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testEmptyIpnSecretIsRejected(): void
    {
        [$order, $payment] = $this->nowPayment('');
        $this->apiReturnsStatus('finished');

        $this->postIpn(['payment_id' => 'np_1', 'payment_status' => 'finished'], 'anything');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertNotConfirmed($order, $payment);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        [$order, $payment] = $this->nowPayment(self::SECRET);
        $this->apiReturnsStatus('finished');

        $this->postIpn(['payment_id' => 'np_1', 'payment_status' => 'finished'], str_repeat('a', 128));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertNotConfirmed($order, $payment);
    }

    public function testMissingSignatureIsRejected(): void
    {
        [$order, $payment] = $this->nowPayment(self::SECRET);

        $this->postIpn(['payment_id' => 'np_1', 'payment_status' => 'finished'], null);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertNotConfirmed($order, $payment);
    }

    public function testSignedButForgedFinishedBodyIsNotConfirmedWhenApiSaysWaiting(): void
    {
        [$order, $payment] = $this->nowPayment(self::SECRET);
        $this->apiReturnsStatus('waiting');

        $body = ['payment_id' => 'np_1', 'payment_status' => 'finished', 'actually_paid' => '999'];
        $this->postIpn($body, $this->sign($body));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->http->requests('/v1/payment/np_1'), 'status must be re-read from the API');
        $this->assertNotConfirmed($order, $payment);
    }

    public function testLookupByOrderIdIsNotPossible(): void
    {
        [$order, $payment] = $this->nowPayment(self::SECRET);
        $this->apiReturnsStatus('finished');

        $body = ['order_id' => (string) $order->getId(), 'payment_status' => 'finished'];
        $this->postIpn($body, $this->sign($body));

        self::assertStringContainsString('payment_not_found', (string) $this->client->getResponse()->getContent());
        $this->assertNotConfirmed($order, $payment);
    }

    public function testSignedIpnConfirmedByApiProvisionsOnce(): void
    {
        [$order, $payment] = $this->nowPayment(self::SECRET);
        $this->apiReturnsStatus('finished');

        $body = ['payment_id' => 'np_1', 'payment_status' => 'finished'];
        $this->postIpn($body, $this->sign($body));
        $this->postIpn($body, $this->sign($body));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    /**
     * @return array{Order, Payment}
     */
    private function nowPayment(string $ipnSecret): array
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, array_filter([
            'api_key' => 'test-api-key',
            'ipn_secret' => $ipnSecret,
        ]));
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        // As NowPaymentsGateway::createPayment() would: the quote the API must report back.
        $payment->setCryptoPaymentId('np_1')->setGatewayTransactionId('np_1')->setExpectation('10', 'usd');
        $this->em->flush();

        return [$order, $payment];
    }

    private function apiReturnsStatus(string $status): void
    {
        $this->http->onJson('/v1/payment/np_1', [
            'payment_id' => 'np_1',
            'payment_status' => $status,
            'price_amount' => 10,
            'price_currency' => 'usd',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sign(array $body): string
    {
        ksort($body);

        return hash_hmac('sha512', (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::SECRET);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postIpn(array $body, ?string $signature): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $signature) {
            $server['HTTP_X_NOWPAYMENTS_SIG'] = $signature;
        }
        $this->client->request('POST', '/payment/webhook/nowpayments', [], [], $server, (string) json_encode($body));
    }

    private function assertNotConfirmed(Order $order, Payment $payment): void
    {
        self::assertNotSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));
    }
}
