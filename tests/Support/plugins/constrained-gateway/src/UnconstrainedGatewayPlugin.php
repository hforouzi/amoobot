<?php

declare(strict_types=1);

namespace Amoobot\Plugin\ConstrainedGateway;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PaymentWebhookResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test only: a plugin that declares no constraints, so nothing about it changes.
 */
class UnconstrainedGatewayPlugin implements PaymentGatewayPluginInterface
{
    public function getType(): string
    {
        return 'constrained_fixture';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult
    {
        return new PaymentRequestResult(success: false, message: 'Test plugin: no invoices.');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        return new PaymentVerificationResult(success: true, paid: false);
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
