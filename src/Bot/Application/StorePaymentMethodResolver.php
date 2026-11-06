<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\Order;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginRegistry;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class StorePaymentMethodResolver
{
    private const GATEWAY_NOT_USABLE = 'gateway_not_usable';
    private const AMOUNT_OUT_OF_RANGE = 'amount_out_of_range';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PaymentPluginDoctor $pluginDoctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly LoggerInterface $logger,
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
     * The methods a customer can be offered at all, whatever the order: active, on an active gateway that is configured
     * and has its driver (plugin enabled and healthy), and a plugin that declares constraints calls usable. The
     * order-dependent rules (currency, amount range, order state) are not applied. For the customer guide's list of payment methods, which shows their titles only.
     *
     * @return list<StorePaymentMethod>
     */
    public function getOfferableMethods(): array
    {
        $offerable = [];
        foreach ($this->allMethods() as $method) {
            $gateway = $method->getGateway();
            if (!$method->isActive() || !$gateway->isActive() || null !== $this->pluginStatusReason($gateway)) {
                continue;
            }
            $driver = $this->driver($gateway, true);
            if (!is_string($driver) && $this->isGatewayConfiguredForResolver($gateway) && !in_array(self::GATEWAY_NOT_USABLE, $this->constraintSkipReasons($driver, $gateway, null), true)) {
                $offerable[] = $method;
            }
        }

        return $offerable;
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
            // A reseller's order is paid from his wallet only (ResellerCheckout), never through a gateway or a receipt.
            if ($order->isResellerOrder()) {
                $skipReasons[] = 'reseller_order_wallet_only';
            }
            $gateway = $method->getGateway();
            $gatewayType = trim((string) $gateway->getType());
            $methodCurrency = $this->normalizeCurrency($method->getCurrency());

            if (!$method->isActive()) {
                $skipReasons[] = 'method_inactive';
            }
            if (!$gateway->isActive()) {
                $skipReasons[] = 'gateway_inactive';
            }

            $pluginStatusReason = $this->pluginStatusReason($gateway);
            if (null !== $pluginStatusReason) {
                $skipReasons[] = $pluginStatusReason;
            }

            // A plugin with a status reason has no driver: resolving it would only add the registry's warning to every
            // listing. Nor is a failure to load worth a warning on a method or gateway that is switched off.
            $driver = $pluginStatusReason ?? $this->driver($gateway, $method->isActive() && $gateway->isActive());
            $hasDriver = !is_string($driver);
            if (is_string($driver) && null === $pluginStatusReason) {
                $skipReasons[] = $driver;
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
                $skipReasons[] = self::AMOUNT_OUT_OF_RANGE;
            }

            if (!is_string($driver)) {
                $skipReasons = array_values(array_unique([...$skipReasons, ...$this->constraintSkipReasons($driver, $gateway, $orderPayableAmount)]));
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

    /**
     * The gateway's driver, or the reason it has none. $logFailure: the method is on offer, so a plugin that does not
     * load is unexpected.
     */
    private function driver(PaymentGateway $gateway, bool $logFailure): PaymentGatewayInterface|string
    {
        $gatewayType = trim($gateway->getType());
        if ('' === $gatewayType) {
            return 'gateway_driver_missing';
        }

        try {
            return $this->paymentGatewayRegistry->resolve($gateway);
        } catch (\Throwable $exception) {
            if ($logFailure && null !== $gateway->getPluginCode()) {
                $this->logSkipped('The payment plugin could not be loaded; the method is skipped.', $gateway, $exception);
            }
            $message = $exception->getMessage();
            $reason = trim((string) strtok($message, ':'));

            return '' === $reason ? 'gateway_driver_missing' : $reason;
        }
    }

    /**
     * What a driver with constraints refuses (PaymentGatewayConstraintsInterface; nothing for any other driver). A
     * check that throws refuses the method. $amount null: only isUsable().
     *
     * @return list<string>
     */
    private function constraintSkipReasons(PaymentGatewayInterface $driver, PaymentGateway $gateway, ?int $amount): array
    {
        if (!$driver instanceof PaymentGatewayConstraintsInterface) {
            return [];
        }

        try {
            $reasons = $driver->isUsable($gateway->getConfig() ?? []) ? [] : [self::GATEWAY_NOT_USABLE];
            if (null !== $amount && !$driver->acceptsAmount($amount)) {
                $reasons[] = self::AMOUNT_OUT_OF_RANGE;
            }

            return $reasons;
        } catch (\Throwable $exception) {
            $this->logSkipped('A payment plugin constraint check failed; the method is skipped.', $gateway, $exception);

            return [self::GATEWAY_NOT_USABLE];
        }
    }

    /** The exception's class only: its message may quote the gateway's config. */
    private function logSkipped(string $message, PaymentGateway $gateway, \Throwable $exception): void
    {
        $this->logger->warning($message, ['gateway_id' => $gateway->getId(), 'plugin_code' => $gateway->getPluginCode(), 'error_class' => $exception::class]);
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

    private function pluginStatusReason(PaymentGateway $gateway): ?string
    {
        $pluginCode = $gateway->getPluginCode();
        if (null === $pluginCode) {
            return null;
        }

        $plugin = $this->pluginRegistry->findByCode($pluginCode);
        if (null === $plugin) {
            return 'plugin_missing';
        }
        if ('error' === $plugin->getStatus()) {
            return 'plugin_error';
        }
        if ('enabled' !== $plugin->getStatus()) {
            return 'plugin_disabled';
        }

        $doctor = $this->pluginDoctor->inspect($plugin);
        if (!$doctor->ok()) {
            if (in_array('class_not_found', $doctor->errors, true)) {
                return 'class_not_found';
            }
            if (in_array('interface_not_implemented', $doctor->errors, true)) {
                return 'interface_not_implemented';
            }

            return 'plugin_error';
        }

        return null;
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
        return $this->schemaValidator->isConfigured($manifest['configSchema'] ?? [], $gateway->getConfig());
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
