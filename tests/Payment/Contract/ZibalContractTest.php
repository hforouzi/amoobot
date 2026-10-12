<?php

declare(strict_types=1);

namespace App\Tests\Payment\Contract;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Infrastructure\ZibalGateway;
use Symfony\Component\HttpFoundation\Request;

final class ZibalContractTest extends GatewayContractTestCase
{
    protected function gatewayDriver(): PaymentGatewayInterface
    {
        return self::getContainer()->get(ZibalGateway::class);
    }

    protected function createGatewayEntity(): PaymentGateway
    {
        return $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'test-merchant', 'callback_base_url' => 'https://shop.test']);
    }

    protected function channel(): string
    {
        return NotificationAwareGatewayInterface::CHANNEL_CALLBACK;
    }

    protected function prepareCreatedPayment(Payment $payment): void
    {
        $payment->setGatewayTransactionId('trk-'.$payment->getId())->setAuthority('trk-'.$payment->getId());
    }

    protected function providerSaysPaid(Payment $payment): void
    {
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => self::PRICE_TOMAN * 10, 'refNumber' => 'REF1']);
    }

    protected function providerSaysUnpaid(Payment $payment): void
    {
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 202, 'message' => 'not paid']);
    }

    protected function forgedPaidNotification(Payment $payment): Request
    {
        return self::request('GET', ['trackId' => 'trk-'.$payment->getId(), 'success' => '1', 'status' => '2']);
    }

    protected function notificationWithInternalIdsOnly(Payment $payment): Request
    {
        return self::request('GET', ['orderId' => (string) $payment->getOrder()->getId(), 'payment_id' => (string) $payment->getId(), 'success' => '1']);
    }

    public function testVerifyUsesStoredTrackIdNotTheRequestOne(): void
    {
        [, $payment] = $this->createdPayment();
        $this->providerSaysPaid($payment);

        $this->gatewayDriver()->verifyPayment($payment, ['trackId' => 'someone-elses-paid-track']);

        $sent = json_decode((string) $this->http->requests('/v1/verify')[0]['options']['body'], true);
        self::assertSame('trk-'.$payment->getId(), $sent['trackId']);
    }
}
