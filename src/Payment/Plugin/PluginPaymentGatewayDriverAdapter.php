<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;

final class PluginPaymentGatewayDriverAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly PaymentGatewayPluginInterface $inner,
    ) {
    }

    public function getType(): string
    {
        return $this->plugin->getCode();
    }

    public function getPluginClass(): string
    {
        return $this->inner::class;
    }

    public function createPayment(Payment $payment, Order $order): PaymentRequestResult
    {
        return $this->inner->createPayment($payment, $order, $this->gatewayConfig($payment));
    }

    public function verifyPayment(Payment $payment, array $payload = []): PaymentVerificationResult
    {
        return $this->inner->verifyPayment($payment, $payload, $this->gatewayConfig($payment));
    }

    public function supportsWebhook(): bool
    {
        return $this->inner->supportsWebhook();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleWebhook(Payment $payment, array $payload, Request $request): ?PaymentWebhookResult
    {
        return $this->inner->handleWebhook($payload, $request, $this->gatewayConfig($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConfig(Payment $payment): array
    {
        $gateway = $payment->getGateway();

        return null !== $gateway && is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
    }
}
