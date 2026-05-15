<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\Order;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Plugin\PluginRegistry;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class StorePaymentMethodResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PluginRegistry $pluginRegistry,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly string $paymentCardNumber = '',
        private readonly string $paymentCardHolder = '',
    ) {
    }

    /**
     * @return list<StorePaymentMethod>
     */
    public function getAvailableMethods(Order $order): array
    {
        $evaluations = $this->evaluateMethods($order);
        $available = [];

        foreach ($evaluations as $evaluation) {
            if (true !== ($evaluation['accepted'] ?? false)) {
                continue;
            }

            $method = $evaluation['method'] ?? null;
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }

            $available[] = $method;
        }

        return $available;
    }

    /**
     * @return array{
     *     orderId: int,
     *     amount: int,
     *     payableAmount: int,
     *     currency: string,
     *     activeStorePaymentMethodCount: int,
     *     skippedReasons: list<string>,
     *     methods: list<array<string, mixed>>
     * }
     */
    public function getDiagnostics(Order $order): array
    {
        $evaluations = $this->evaluateMethods($order);
        $currency = $this->resolveCurrency($order);
        $amount = max(0, $order->getAmount());
        $payableAmount = $this->resolvePayableAmount($order);
        $activeCount = 0;
        $skippedReasons = [];
        $methods = [];

        foreach ($evaluations as $evaluation) {
            $method = $evaluation['method'] ?? null;
            if (!$method instanceof StorePaymentMethod) {
                continue;
            }
            if (true === ($evaluation['methodIsActive'] ?? false)) {
                ++$activeCount;
            }
            $skipReasons = $evaluation['skipReasons'] ?? [];
            if (is_array($skipReasons) && [] !== $skipReasons) {
                $skippedReasons[] = sprintf(
                    'method_id=%d reasons=%s',
                    (int) ($method->getId() ?? 0),
                    implode('|', array_map(static fn (mixed $reason): string => (string) $reason, $skipReasons))
                );
            }
            unset($evaluation['method']);
            $methods[] = $evaluation;
        }

        return [
            'orderId' => (int) ($order->getId() ?? 0),
            'amount' => $amount,
            'payableAmount' => $payableAmount,
            'currency' => $currency,
            'activeStorePaymentMethodCount' => $activeCount,
            'skippedReasons' => $skippedReasons,
            'methods' => $methods,
        ];
    }

    /**
     * @return list<StorePaymentMethod>
     */
    private function allMethods(): array
    {
        $qb = $this->entityManager->getRepository(StorePaymentMethod::class)->createQueryBuilder('m');
        $qb->leftJoin('m.gateway', 'g')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        $methods = $qb->getQuery()->getResult();

        return array_values(array_filter(
            is_array($methods) ? $methods : [],
            static fn (mixed $item): bool => $item instanceof StorePaymentMethod
        ));
    }

    /**
     * @return list<array{
     *   method: StorePaymentMethod,
     *   accepted: bool,
     *   methodId: int,
     *   methodTitle: string,
     *   methodIsActive: bool,
     *   methodCurrency: string,
     *   methodMinAmount: ?int,
     *   methodMaxAmount: ?int,
     *   gatewayId: int,
     *   gatewayType: string,
     *   gatewayTitle: string,
     *   gatewayIsActive: bool,
     *   gatewayConfigured: bool,
     *   hasDriver: bool,
     *   orderAmount: int,
     *   orderPayableAmount: int,
     *   orderCurrency: string,
     *   skipReason: string,
     *   skipReasons: list<string>
     * }>
     */
    private function evaluateMethods(Order $order): array
    {
        $orderAmount = max(0, $order->getAmount());
        $orderPayableAmount = $this->resolvePayableAmount($order);
        $orderCurrency = $this->resolveCurrency($order);
        $results = [];

        foreach ($this->allMethods() as $method) {
            $skipReasons = [];
            $gateway = $method->getGateway();
            $gatewayType = trim((string) $gateway->getType());
            $methodCurrency = $this->normalizeCurrency($method->getCurrency());

            if (!$method->isActive()) {
                $skipReasons[] = 'method_inactive';
            }
            if (!$gateway->isActive()) {
                $skipReasons[] = 'gateway_inactive';
            }

            $pluginDisabled = $this->isPluginGatewayDisabled($gateway);
            if ($pluginDisabled) {
                $skipReasons[] = 'plugin disabled';
            }

            $hasDriver = $this->hasDriver($gatewayType);
            if (!$hasDriver && !$pluginDisabled) {
                $skipReasons[] = 'gateway_driver_missing';
            }

            $gatewayConfigured = $this->isGatewayConfiguredForResolver($gateway);
            if (!$gatewayConfigured) {
                $skipReasons[] = PaymentGatewayType::MANUAL_CARD === $gatewayType
                    ? 'gateway_not_configured_manual_card_missing_card_config'
                    : 'gateway_not_configured';
            }

            if ($methodCurrency !== $orderCurrency) {
                $skipReasons[] = 'currency_mismatch';
            }

            if (!$method->isAmountAllowed($orderPayableAmount)) {
                $skipReasons[] = 'amount_out_of_range';
            }

            if (
                PaymentGatewayType::NOWPAYMENTS === $gatewayType
                && null !== $gateway->getNowPaymentsMinOrderAmountToman()
                && $orderPayableAmount < (int) $gateway->getNowPaymentsMinOrderAmountToman()
            ) {
                $skipReasons[] = 'below minimum order amount for NOWPayments';
            }

            if (
                (int) ($order->getId() ?? 0) > 0
                && $order->getStatus() !== OrderStatus::WAITING_PAYMENT
            ) {
                $skipReasons[] = 'order_not_waiting_payment';
            }

            $results[] = [
                'method' => $method,
                'accepted' => [] === $skipReasons,
                'methodId' => (int) ($method->getId() ?? 0),
                'methodTitle' => $method->getTitle(),
                'methodIsActive' => $method->isActive(),
                'methodCurrency' => $methodCurrency,
                'methodMinAmount' => $method->getMinAmount(),
                'methodMaxAmount' => $method->getMaxAmount(),
                'gatewayId' => (int) ($gateway->getId() ?? 0),
                'gatewayType' => $gatewayType,
                'gatewayTitle' => $gateway->getTitle(),
                'gatewayIsActive' => $gateway->isActive(),
                'gatewayConfigured' => $gatewayConfigured,
                'hasDriver' => $hasDriver,
                'orderAmount' => $orderAmount,
                'orderPayableAmount' => $orderPayableAmount,
                'orderCurrency' => $orderCurrency,
                'skipReason' => [] === $skipReasons ? 'accepted' : implode('|', $skipReasons),
                'skipReasons' => $skipReasons,
            ];
        }

        return $results;
    }

    private function hasDriver(string $gatewayType): bool
    {
        if ('' === $gatewayType) {
            return false;
        }

        try {
            return $this->paymentGatewayRegistry->supportsType($gatewayType);
        } catch (\Throwable) {
            return false;
        }
    }

    private function isGatewayConfiguredForResolver(PaymentGateway $gateway): bool
    {
        return match ($gateway->getType()) {
            PaymentGatewayType::MANUAL_CARD => $this->isManualCardConfiguredForResolver($gateway),
            PaymentGatewayType::ZIBAL => $this->isZibalConfigured($gateway),
            PaymentGatewayType::CUSTOM_API => $gateway->isConfigured(),
            PaymentGatewayType::NOWPAYMENTS => $gateway->isNowPaymentsConfigured(),
            default => $this->isPluginGatewayConfigured($gateway),
        };
    }

    private function isPluginGatewayDisabled(PaymentGateway $gateway): bool
    {
        $pluginCode = $gateway->getPluginCode();
        if (null === $pluginCode) {
            return false;
        }

        $plugin = $this->pluginRegistry->findByCode($pluginCode);

        return null === $plugin || 'enabled' !== $plugin->getStatus();
    }

    private function isPluginGatewayConfigured(PaymentGateway $gateway): bool
    {
        $pluginCode = $gateway->getPluginCode();
        if (null === $pluginCode) {
            return false;
        }

        $plugin = $this->pluginRegistry->findByCode($pluginCode);
        if (null === $plugin || 'enabled' !== $plugin->getStatus()) {
            return false;
        }

        $manifest = $plugin->getManifest();
        $schema = is_array($manifest['configSchema'] ?? null) ? $manifest['configSchema'] : [];
        $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
        foreach ($schema as $field) {
            if (!is_array($field) || true !== ($field['required'] ?? false)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? $field['key'] ?? ''));
            if ('' === $name) {
                continue;
            }

            $value = $config[$name] ?? null;
            if (null === $value || '' === trim((string) $value)) {
                return false;
            }
        }

        return true;
    }

    private function isManualCardConfiguredForResolver(PaymentGateway $gateway): bool
    {
        $cardNumber = trim((string) ($gateway->getManualCardNumber() ?? ''));
        $cardHolder = trim((string) ($gateway->getManualCardHolder() ?? ''));
        if ('' !== $cardNumber && '' !== $cardHolder) {
            return true;
        }

        $legacyCardNumber = trim((string) ($this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber) ?? ''));
        $legacyCardHolder = trim((string) ($this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder) ?? ''));

        return '' !== $legacyCardNumber && '' !== $legacyCardHolder;
    }

    private function isZibalConfigured(PaymentGateway $gateway): bool
    {
        $merchant = trim((string) ($gateway->getZibalMerchant() ?? ''));
        $callbackBaseUrl = trim((string) ($gateway->getZibalCallbackBaseUrl() ?? ''));

        return '' !== $merchant && '' !== $callbackBaseUrl;
    }

    private function resolveCurrency(Order $order): string
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];

        return $this->normalizeCurrency((string) ($metadata['currency'] ?? 'IRR'));
    }

    private function resolvePayableAmount(Order $order): int
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $payable = $metadata['payableAmount'] ?? null;
        $value = is_numeric($payable) ? (int) $payable : $order->getAmount();

        return max(0, $value);
    }

    private function normalizeCurrency(?string $currency): string
    {
        $normalized = strtoupper(trim((string) $currency));

        return '' === $normalized ? 'IRR' : $normalized;
    }
}
