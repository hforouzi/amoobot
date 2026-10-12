<?php

declare(strict_types=1);

namespace App\Tests\Payment\Contract;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Infrastructure\CustomApiGateway;
use Symfony\Component\HttpFoundation\Request;

final class CustomApiContractTest extends GatewayContractTestCase
{
    private const SECRET = 'psp-secret';

    protected function gatewayDriver(): PaymentGatewayInterface
    {
        return self::getContainer()->get(CustomApiGateway::class);
    }

    /**
     * @param array<string, mixed> $webhookOverrides
     */
    private function config(array $webhookOverrides = []): array
    {
        return [
            'verify' => [
                'url' => 'https://psp.test/verify',
                'method' => 'POST',
                'body' => ['tx' => '{{ transaction_id }}'],
                'response_mapping' => [
                    'success' => 'ok',
                    'paid' => 'state',
                    'paid_values' => ['paid'],
                    'amount' => 'amount',
                    'currency' => 'currency',
                ],
            ],
            'webhook' => array_replace(['enabled' => true, 'secret_header' => 'X-Psp-Secret', 'secret' => self::SECRET], $webhookOverrides),
        ];
    }

    protected function createGatewayEntity(): PaymentGateway
    {
        return $this->createGateway(PaymentGatewayType::CUSTOM_API, $this->config());
    }

    protected function prepareCreatedPayment(Payment $payment): void
    {
        $payment->setGatewayTransactionId('tx-'.$payment->getId());
    }

    protected function providerSaysPaid(Payment $payment): void
    {
        $this->http->onJson('psp.test/verify', ['ok' => true, 'state' => 'paid', 'amount' => (string) (self::PRICE_TOMAN * 10), 'currency' => 'IRR']);
    }

    protected function providerSaysUnpaid(Payment $payment): void
    {
        $this->http->onJson('psp.test/verify', ['ok' => true, 'state' => 'pending']);
    }

    protected function forgedPaidNotification(Payment $payment): Request
    {
        return self::request('POST', [], ['transaction_id' => 'tx-'.$payment->getId(), 'status' => 'paid', 'amount' => '999999999'], ['X-Psp-Secret' => self::SECRET]);
    }

    protected function notificationWithInternalIdsOnly(Payment $payment): Request
    {
        return self::request('POST', [], ['payment_id' => (string) $payment->getId(), 'order_id' => (string) $payment->getOrder()->getId()], ['X-Psp-Secret' => self::SECRET]);
    }

    public function testWebhookWithoutConfiguredSecretIsRejected(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $gateway->setConfig($this->config(['secret' => '']));
        $this->em->flush();
        $this->providerSaysPaid($payment);

        $outcome = $this->processor()->process([$gateway], $this->forgedPaidNotification($payment), NotificationAwareGatewayInterface::CHANNEL_WEBHOOK, 'test');

        self::assertSame(403, $outcome->httpStatus);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testWebhookWithWrongSecretIsRejected(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $this->providerSaysPaid($payment);
        $request = self::request('POST', [], ['transaction_id' => 'tx-'.$payment->getId()], ['X-Psp-Secret' => 'guess']);

        $outcome = $this->processor()->process([$gateway], $request, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK, 'test');

        self::assertSame(401, $outcome->httpStatus);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testVerifyWithoutAmountMappingGoesToReview(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $config = $this->config();
        unset($config['verify']['response_mapping']['amount'], $config['verify']['response_mapping']['currency']);
        $gateway->setConfig($config);
        $this->em->flush();
        $this->providerSaysPaid($payment);

        $outcome = $this->processor()->process([$gateway], $this->forgedPaidNotification($payment), NotificationAwareGatewayInterface::CHANNEL_WEBHOOK, 'test');

        self::assertSame('needs_review', $outcome->code);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testCallbackCannotSelectPaymentByInternalId(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $this->providerSaysPaid($payment);

        $outcome = $this->processor()->process([$gateway], self::request('GET', ['payment_id' => (string) $payment->getId()]), NotificationAwareGatewayInterface::CHANNEL_CALLBACK, 'test');

        self::assertSame('payment_not_found', $outcome->code);
        self::assertSame(0, $this->countServicesForOrder($order));
    }
}
