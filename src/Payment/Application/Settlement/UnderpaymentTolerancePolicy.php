<?php

declare(strict_types=1);

namespace App\Payment\Application\Settlement;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use App\Payment\Domain\Money\Decimal;
use Psr\Log\LoggerInterface;

/**
 * How much less than expected a gateway may report before a payment goes to NEEDS_REVIEW.
 *
 * Per gateway: config key `underpayment_tolerance_percent` (e.g. 0, 0.5, 1). Without it the default
 * is 0 for fiat gateways and 1 for crypto gateways (module category "crypto": NOWPayments, crypto
 * plugins), whose network fees and exchange-rate moves make tiny shortfalls common.
 * Invalid values fall back to 0 (strict); values above MAX_PERCENT are capped.
 */
class UnderpaymentTolerancePolicy
{
    public const CONFIG_KEY = 'underpayment_tolerance_percent';
    public const FIAT_DEFAULT = '0';
    public const CRYPTO_DEFAULT = '1';
    public const MAX_PERCENT = '10';

    public function __construct(
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function percentFor(Payment $payment): string
    {
        $gateway = $payment->getGateway();
        if (!$gateway instanceof PaymentGateway) {
            return self::FIAT_DEFAULT;
        }

        $config = $gateway->getConfig() ?? [];
        $configured = $config[self::CONFIG_KEY] ?? null;
        if (null !== $configured && '' !== trim((string) (is_scalar($configured) ? $configured : ''))) {
            return $this->parse($configured, $gateway);
        }

        return $this->isCrypto($gateway) ? self::CRYPTO_DEFAULT : self::FIAT_DEFAULT;
    }

    private function parse(mixed $value, PaymentGateway $gateway): string
    {
        $percent = is_int($value) || is_float($value) || is_string($value) ? Decimal::normalize($value) : null;
        if (null === $percent) {
            $this->logger->warning('Invalid underpayment tolerance; using 0.', ['gateway_id' => $gateway->getId()]);

            return '0';
        }
        if (Decimal::compare($percent, self::MAX_PERCENT) > 0) {
            $this->logger->warning('Underpayment tolerance capped.', ['gateway_id' => $gateway->getId(), 'max' => self::MAX_PERCENT]);

            return self::MAX_PERCENT;
        }

        return $percent;
    }

    private function isCrypto(PaymentGateway $gateway): bool
    {
        try {
            return 'crypto' === ($this->moduleRegistry->get($gateway->getType())['category'] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }
}
