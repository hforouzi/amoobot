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

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        if (($config['demo_mode'] ?? 'success_url') === 'failure') {
            return new PaymentRequestResult(
                success: false,
                message: 'Demo payment failure',
                rawResponse: ['demo' => true],
            );
        }

        $paymentId = (int) ($payment->getId() ?? 0);
        $transactionId = 'demo_'.($paymentId > 0 ? (string) $paymentId : bin2hex(random_bytes(6)));

        return new PaymentRequestResult(
            success: true,
            paymentUrl: trim((string) ($config['payment_url'] ?? '')) ?: 'https://example.com/demo-payment',
            transactionId: $transactionId,
            authority: $transactionId,
            message: 'Demo payment created',
            rawResponse: ['demo' => true],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $paid = true === ($payload['demo_paid'] ?? false) || 'true' === (string) ($payload['demo_paid'] ?? '');

        return new PaymentVerificationResult(
            success: true,
            paid: $paid,
            transactionId: $paid ? ($payment->getGatewayTransactionId() ?? 'demo_paid') : null,
            refId: $paid ? 'demo_ref' : null,
            message: $paid ? 'Demo payment verified' : 'Demo payment is not paid',
            rawResponse: ['demo' => true, 'paid' => $paid],
        );
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult
    {
        return null;
    }
}
