<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class WebhookRoutesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testUnifiedRouteAndAliasShareThePipeline(): void
    {
        $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's']);

        foreach (['/payment/webhook/nowpayments/default', '/payment/webhook/nowpayments'] as $url) {
            $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NOWPAYMENTS_SIG' => 'bad'], '{"payment_id":"x"}');
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testZibalBrowserCallbackSettlesThroughVerify(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setGatewayTransactionId('trk-1')->setAuthority('trk-1');
        $this->em->flush();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000]);

        $this->client->request('GET', '/payment/callback/zibal', ['trackId' => 'trk-1', 'success' => '1']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('تایید شد', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testUnknownGatewayTypeIs404(): void
    {
        $this->client->request('POST', '/payment/webhook/zibal/999');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
