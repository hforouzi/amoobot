<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A gateway refused to create a payment because of its own account: the key is invalid, or the store is disabled at
 * the provider. Every customer who picks the method fails until an admin acts, so the admins are told, at most once an
 * hour per gateway (limiter payment_gateway_credential_alert, keyed by the gateway id).
 *
 * Generic: any gateway may report these codes as rawResponse.error of a failed PaymentRequestResult (the Tetraminator
 * plugin does, for HTTP 401 and 403). The alert text is fixed per code: no provider message, so no key can reach it.
 * Failures are logged, never thrown: the checkout answer stays the same.
 */
final class PaymentGatewayCredentialAlert
{
    public const ERROR_UNAUTHORIZED = 'unauthorized';
    public const ERROR_STORE_DISABLED = 'store_disabled';

    private const DETAILS = [
        self::ERROR_UNAUTHORIZED => 'The gateway rejected its API key (unauthorized). Customers cannot pay with this method until the key is fixed in Admin > Payment Gateways.',
        self::ERROR_STORE_DISABLED => 'The gateway says the store is disabled at the provider. Customers cannot pay with this method until it is enabled in the provider\'s panel.',
    ];

    public function __construct(
        private readonly AdminPaymentAlerter $alerter,
        #[Autowire(service: 'limiter.payment_gateway_credential_alert')]
        private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed>|null $rawResponse the failed PaymentRequestResult's
     */
    public function afterFailedCreate(Payment $payment, ?array $rawResponse): void
    {
        $code = $rawResponse['error'] ?? null;
        $gatewayId = $payment->getGateway()?->getId();
        if (!is_string($code) || !isset(self::DETAILS[$code]) || null === $gatewayId) {
            return;
        }

        try {
            if (!$this->limiter->create('gateway_'.$gatewayId)->consume()->isAccepted()) {
                return;
            }
            $this->alerter->gatewayAccountRefused($payment, self::DETAILS[$code]);
        } catch (\Throwable $e) {
            $this->logger->error('Gateway credential alert not sent.', ['payment_id' => $payment->getId(), 'error' => $e::class]);
        }
    }
}
