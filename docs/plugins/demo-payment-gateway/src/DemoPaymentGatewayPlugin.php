<?php

declare(strict_types=1);

namespace Amoobot\Plugin\DemoPaymentGateway;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpFoundation\Request;

final class DemoPaymentGatewayPlugin implements PaymentGatewayPluginInterface
{
    public function getType(): string
    {
        return 'demo_payment_gateway';
    }

    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        return new PaymentRequestResult(
            success: false,
            message: 'Demo only. This plugin does not create real payments.'
        );
    }

    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        return new PaymentVerificationResult(
            success: true,
            paid: false,
            message: 'Demo only. No payment was verified.'
        );
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return null;
    }
}
