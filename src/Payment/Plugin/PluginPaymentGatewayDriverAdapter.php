<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Domain\Dto\PaymentRequestResult;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\Webhook\GatewayNotification;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Domain\Webhook\RequestPayload;
use App\Entity\PaymentGateway;
use Symfony\Component\HttpFoundation\Request;

final class PluginPaymentGatewayDriverAdapter implements PaymentGatewayInterface, NotificationAwareGatewayInterface
{
    /** Config key the core sets for every plugin call: true only outside production (test env or debug). */
    public const CONFIG_TESTING_SHORTCUTS = '_amoobot_testing_shortcuts_allowed';
    /** Testing-only config keys removed from a plugin's config in production, whatever the admin stored. */
    public const TESTING_ONLY_CONFIG_KEYS = ['auto_confirm_for_testing'];

    public function __construct(
        private readonly Plugin $plugin,
        private readonly PaymentGatewayPluginInterface $inner,
        // Fail closed: shortcuts are off unless the registry says this is a test/debug kernel.
        private readonly bool $testingShortcutsAllowed = false,
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
     * The plugin's handleWebhook() authenticates the request (and may add data to the payload);
     * the payment is then found by stored references and verified via verifyPayment().
     */
    public function handleNotification(PaymentGateway $gateway, Request $request, string $channel): GatewayNotification
    {
        $payload = RequestPayload::from($request);
        foreach (['token', 'secret'] as $secretKey) {
            unset($payload[$secretKey]);
        }
        $referenceKeys = ['payment_id', 'paymentId', 'transaction_id', 'transactionId', 'authority', 'Authority', 'trackId'];

        // Browser return: no authentication possible; only used to find the payment, verifyPayment() decides.
        if (self::CHANNEL_CALLBACK === $channel) {
            return GatewayNotification::accepted([RequestPayload::firstScalar($payload, $referenceKeys)], $payload);
        }

        if (!$this->inner->supportsWebhook()) {
            return GatewayNotification::rejected(404, 'webhook_not_supported');
        }

        $result = $this->inner->handleWebhook($payload, $request, $this->runtimeConfig(is_array($gateway->getConfig()) ? $gateway->getConfig() : []));
        if (null === $result || !$result->handled) {
            $message = null === $result ? 'webhook_not_handled' : ($result->message ?? 'webhook_not_handled');

            return GatewayNotification::rejected('unauthorized' === $message ? 401 : 400, $message);
        }

        $payload = array_replace($payload, is_array($result->payload) ? $result->payload : []);
        $reference = RequestPayload::firstScalar($payload, $referenceKeys);

        return GatewayNotification::accepted([$reference], $payload, false === ($payload['paid'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayConfig(Payment $payment): array
    {
        $gateway = $payment->getGateway();

        return $this->runtimeConfig(null !== $gateway && is_array($gateway->getConfig()) ? $gateway->getConfig() : []);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function runtimeConfig(array $config): array
    {
        if (!$this->testingShortcutsAllowed) {
            foreach (self::TESTING_ONLY_CONFIG_KEYS as $key) {
                unset($config[$key]);
            }
        }
        $config[self::CONFIG_TESTING_SHORTCUTS] = $this->testingShortcutsAllowed;

        return $config;
    }
}
