<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use Symfony\Component\HttpFoundation\Request;

interface PaymentGatewayPluginInterface
{
    public function getType(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function createPayment(Payment $payment, Order $order, array $config): PaymentRequestResult;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult;

    public function supportsWebhook(): bool;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     */
    public function handleWebhook(array $payload, Request $request, array $config): ?PaymentWebhookResult;
}
