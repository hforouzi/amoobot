<?php

declare(strict_types=1);

namespace App\Tests\Payment\Contract;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\NowPaymentsGateway;
use Symfony\Component\HttpFoundation\Request;

final class NowPaymentsContractTest extends GatewayContractTestCase
{
    private const SECRET = 'contract-ipn-secret';

    protected function gatewayDriver(): PaymentGatewayInterface
    {
        return self::getContainer()->get(NowPaymentsGateway::class);
    }

    protected function createGatewayEntity(): PaymentGateway
    {
        return $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => self::SECRET]);
    }

    protected function prepareCreatedPayment(Payment $payment): void
    {
        $payment->setCryptoPaymentId('np-'.$payment->getId())->setGatewayTransactionId('np-'.$payment->getId())->setExpectation('10', 'usd');
    }

    protected function providerSaysPaid(Payment $payment): void
    {
        $this->http->onJson('/v1/payment/np-'.$payment->getId(), ['payment_status' => 'finished', 'price_amount' => 10, 'price_currency' => 'usd', 'pay_amount' => 0.001, 'actually_paid' => 0.001]);
    }

    protected function providerSaysUnpaid(Payment $payment): void
    {
        $this->http->onJson('/v1/payment/np-'.$payment->getId(), ['payment_status' => 'waiting', 'price_amount' => 10, 'price_currency' => 'usd']);
    }

    protected function forgedPaidNotification(Payment $payment): Request
    {
        return $this->signed(['payment_id' => 'np-'.$payment->getId(), 'payment_status' => 'finished', 'actually_paid' => '999']);
    }

    protected function notificationWithInternalIdsOnly(Payment $payment): Request
    {
        return $this->signed(['order_id' => (string) $payment->getOrder()->getId(), 'payment_status' => 'finished']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function signed(array $body): Request
    {
        $sorted = $body;
        ksort($sorted);
        $signature = hash_hmac('sha512', (string) json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::SECRET);

        return self::request('POST', [], $body, ['x-nowpayments-sig' => $signature]);
    }
}
