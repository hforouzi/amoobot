<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
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

    public function testPaidPaymentIsVerifiedSettledAndShown(): void
    {
        [$order] = $this->nowPayment();
        $this->apiSays('finished');

        $this->client->request('GET', '/payment/success', ['NP_id' => '5550001']);
        $this->client->request('GET', '/payment/success', ['NP_id' => '5550001']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('https://panel.test/sub', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertCount(1, $this->http->requests('/v1/payment/5550001'), 'a confirmed payment is not re-verified');
    }

    public function testPageNeverConfirmsWhatTheProviderDoesNotConfirm(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('waiting');

        $this->client->request('GET', '/payment/success', ['NP_id' => '5550001']);

        self::assertStringContainsString('در حال بررسی است', (string) $this->client->getResponse()->getContent());
        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testUnderpaymentShowsReviewState(): void
    {
        [$order] = $this->nowPayment();
        $this->apiSays('finished', 5.0);

        $this->client->request('GET', '/payment/success', ['NP_id' => '5550001']);

        self::assertStringContainsString('توسط پشتیبانی', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testInternalIdsCannotOpenSomeoneElsesPayment(): void
    {
        [$order, $payment] = $this->nowPayment();
        $this->apiSays('finished');
        $this->client->request('GET', '/payment/success', ['NP_id' => '5550001']); // settle it

        foreach ([['order_id' => (string) $order->getId()], ['payment_id' => (string) $payment->getId()], ['NP_id' => (string) $payment->getId()]] as $query) {
            $this->client->request('GET', '/payment/success', $query);
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('https://panel.test/sub', $content, json_encode($query) ?: '');
        }
    }
}
