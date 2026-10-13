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
    /**
     * A real plugin asks its provider here and reports the amount + currency the PROVIDER confirms.
     *
     * The demo has no provider, so "paid" comes from the request (?demo_paid=true). That is only
     * acceptable on a test/debug installation: Amoobot sets `_amoobot_testing_shortcuts_allowed`
     * (false in production, where it also strips `auto_confirm_for_testing` from the config). In
     * production the demo therefore never reports a payment as paid, so its public callback cannot
     * create NEEDS_REVIEW payments or admin alerts.
     * On a test installation it reports an amount only with `auto_confirm_for_testing`; otherwise
     * the payment lands in NEEDS_REVIEW (Amoobot never auto-confirms without amount + currency).
     */
    public function verifyPayment(Payment $payment, array $payload, array $config): PaymentVerificationResult
    {
        $testingAllowed = true === ($config['_amoobot_testing_shortcuts_allowed'] ?? false);
        $paid = $testingAllowed && (true === ($payload['demo_paid'] ?? false) || 'true' === (string) ($payload['demo_paid'] ?? ''));
        $autoConfirm = $testingAllowed && true === ($config['auto_confirm_for_testing'] ?? false);

        return new PaymentVerificationResult(
            success: true,
            paid: $paid,
            transactionId: $paid ? ($payment->getGatewayTransactionId() ?? 'demo_paid') : null,
            refId: $paid ? 'demo_ref' : null,
            message: $paid ? 'Demo payment verified' : 'Demo payment is not paid',
            rawResponse: ['demo' => true, 'paid' => $paid],
            amount: $paid && $autoConfirm ? (string) ($payment->getPayableAmount() ?? $payment->getAmount()) : null,
            currency: $paid && $autoConfirm ? 'IRT' : null,
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
