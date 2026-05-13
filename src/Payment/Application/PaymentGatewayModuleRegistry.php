<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\PaymentGatewayRegistry;

final class PaymentGatewayModuleRegistry
{
    /**
     * @var array<string, array{
     *   type: string,
     *   displayName: string,
     *   defaultTitle: string,
     *   description: string,
     *   category: string,
     *   supportsWebhook: bool,
     *   supportsOnlinePayment: bool,
     *   defaults: array<string, mixed>,
     *   schema: list<array{name: string, type: string, required: bool, default?: mixed}>
     * }>
     */
    private array $modules;

    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
    ) {
        $this->modules = [
            PaymentGatewayType::MANUAL_CARD => [
                'type' => PaymentGatewayType::MANUAL_CARD,
                'displayName' => 'کارت به کارت',
                'defaultTitle' => 'کارت به کارت',
                'description' => 'ثبت اطلاعات کارت و نمایش راهنمای پرداخت دستی برای کاربر.',
                'category' => 'offline',
                'supportsWebhook' => false,
                'supportsOnlinePayment' => false,
                'defaults' => [
                    'card_number' => '',
                    'card_holder' => '',
                    'bank_name' => '',
                    'instructions' => '',
                ],
                'schema' => [
                    ['name' => 'card_number', 'type' => 'text', 'required' => true],
                    ['name' => 'card_holder', 'type' => 'text', 'required' => true],
                    ['name' => 'bank_name', 'type' => 'text', 'required' => false],
                    ['name' => 'instructions', 'type' => 'textarea', 'required' => false],
                ],
            ],
            PaymentGatewayType::ZIBAL => [
                'type' => PaymentGatewayType::ZIBAL,
                'displayName' => 'زیبال',
                'defaultTitle' => 'زیبال',
                'description' => 'درگاه آنلاین ریالی زیبال برای پرداخت مستقیم کاربر.',
                'category' => 'online',
                'supportsWebhook' => true,
                'supportsOnlinePayment' => true,
                'defaults' => [
                    'merchant' => 'zibal',
                    'sandbox' => false,
                    'callback_base_url' => '',
                ],
                'schema' => [
                    ['name' => 'merchant', 'type' => 'text', 'required' => true],
                    ['name' => 'sandbox', 'type' => 'boolean', 'required' => false, 'default' => false],
                    ['name' => 'callback_base_url', 'type' => 'text', 'required' => true],
                ],
            ],
            PaymentGatewayType::NOWPAYMENTS => [
                'type' => PaymentGatewayType::NOWPAYMENTS,
                'displayName' => 'NOWPayments',
                'defaultTitle' => 'NOWPayments',
                'description' => 'پرداخت رمزارزی NOWPayments با حالت invoice یا payment.',
                'category' => 'crypto',
                'supportsWebhook' => true,
                'supportsOnlinePayment' => true,
                'defaults' => [
                    'api_key' => '',
                    'ipn_secret' => '',
                    'api_base_url' => 'https://api.nowpayments.io/v1',
                    'payment_mode' => 'invoice',
                    'price_currency' => 'usd',
                    'pay_currency' => 'usdttrc20',
                    'amount_unit' => 'toman',
                    'callback_base_url' => '',
                    'success_url' => '',
                    'cancel_url' => '',
                ],
                'schema' => [
                    ['name' => 'api_key', 'type' => 'password', 'required' => true],
                    ['name' => 'ipn_secret', 'type' => 'password', 'required' => false],
                    ['name' => 'api_base_url', 'type' => 'text', 'required' => false, 'default' => 'https://api.nowpayments.io/v1'],
                    ['name' => 'payment_mode', 'type' => 'choice', 'required' => true, 'default' => 'invoice'],
                    ['name' => 'price_currency', 'type' => 'text', 'required' => true, 'default' => 'usd'],
                    ['name' => 'pay_currency', 'type' => 'text', 'required' => false, 'default' => 'usdttrc20'],
                    ['name' => 'amount_unit', 'type' => 'choice', 'required' => false, 'default' => 'toman'],
                    ['name' => 'toman_per_usd', 'type' => 'integer', 'required' => false],
                    ['name' => 'callback_base_url', 'type' => 'text', 'required' => true],
                    ['name' => 'success_url', 'type' => 'text', 'required' => false],
                    ['name' => 'cancel_url', 'type' => 'text', 'required' => false],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_keys($this->modules);
    }

    public function supports(string $type): bool
    {
        return isset($this->modules[$type]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $type): array
    {
        if (!$this->supports($type)) {
            throw new \InvalidArgumentException(sprintf('Unsupported payment gateway module "%s".', $type));
        }

        return $this->modules[$type];
    }

    /**
     * @return array<string, string>
     */
    public function choiceMap(): array
    {
        $choices = [];
        foreach ($this->modules as $type => $module) {
            $choices[(string) $module['displayName']] = $type;
        }

        return $choices;
    }

    public function displayName(string $type): string
    {
        return (string) ($this->modules[$type]['displayName'] ?? $type);
    }

    public function defaultTitle(string $type): string
    {
        return (string) ($this->modules[$type]['defaultTitle'] ?? $this->displayName($type));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(string $type): array
    {
        return (array) ($this->modules[$type]['defaults'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function requiredConfigFields(string $type): array
    {
        $required = [];
        foreach ((array) ($this->modules[$type]['schema'] ?? []) as $field) {
            if (true === ($field['required'] ?? false)) {
                $required[] = (string) ($field['name'] ?? '');
            }
        }

        return array_values(array_filter($required, static fn (string $name): bool => '' !== $name));
    }

    public function exampleJson(?string $type = null): string
    {
        $examples = [
            PaymentGatewayType::MANUAL_CARD => [
                'card_number' => '6037990000000000',
                'card_holder' => 'Name',
                'bank_name' => 'Bank',
                'instructions' => 'After transfer send receipt.',
            ],
            PaymentGatewayType::ZIBAL => [
                'merchant' => 'zibal',
                'sandbox' => true,
                'callback_base_url' => 'https://example.com',
            ],
            PaymentGatewayType::NOWPAYMENTS => [
                'api_key' => 'YOUR_API_KEY',
                'ipn_secret' => 'YOUR_IPN_SECRET',
                'api_base_url' => 'https://api.nowpayments.io/v1',
                'payment_mode' => 'invoice',
                'price_currency' => 'usd',
                'pay_currency' => 'usdttrc20',
                'amount_unit' => 'toman',
                'toman_per_usd' => 60000,
                'callback_base_url' => 'https://example.com',
                'success_url' => 'https://example.com/payment/success',
                'cancel_url' => 'https://example.com/payment/cancel',
            ],
        ];

        if (null !== $type && isset($examples[$type])) {
            return (string) json_encode($examples[$type], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $lines = [];
        foreach ($examples as $exampleType => $payload) {
            $lines[] = $exampleType.':';
            $lines[] = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = '';
        }

        return trim(implode(PHP_EOL, $lines));
    }

    public function configHelp(?string $type = null): string
    {
        if (null !== $type && $this->supports($type)) {
            $module = $this->get($type);

            return trim(sprintf(
                "%s\n\nRequired: %s\n\n%s",
                (string) $module['description'],
                implode(', ', $this->requiredConfigFields($type)),
                $this->exampleJson($type),
            ));
        }

        $sections = [
            'Supported modules: '.implode(', ', array_map(fn (array $module): string => (string) $module['type'], $this->all())),
            $this->exampleJson(),
        ];

        return implode("\n\n", $sections);
    }

    public function isImplemented(string $type): bool
    {
        try {
            $this->gatewayRegistry->resolveByType($type);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isConfigured(PaymentGateway $gateway): bool
    {
        if (!$this->supports($gateway->getType())) {
            return false;
        }

        return match ($gateway->getType()) {
            PaymentGatewayType::MANUAL_CARD => null !== $gateway->getManualCardNumber()
                && null !== $gateway->getManualCardHolder(),
            PaymentGatewayType::ZIBAL => null !== $gateway->getZibalMerchant()
                && null !== $gateway->getZibalCallbackBaseUrl(),
            PaymentGatewayType::NOWPAYMENTS => $this->isNowPaymentsConfigured($gateway),
            default => false,
        };
    }

    private function isNowPaymentsConfigured(PaymentGateway $gateway): bool
    {
        if (null === $gateway->getNowPaymentsApiKey() || null === $gateway->getNowPaymentsCallbackBaseUrl()) {
            return false;
        }

        if (null === $gateway->getNowPaymentsPriceCurrency() || '' === trim($gateway->getNowPaymentsPaymentMode())) {
            return false;
        }

        if ('payment' === $gateway->getNowPaymentsPaymentMode() && null === $gateway->getNowPaymentsPayCurrency()) {
            return false;
        }

        if ('IRR' === strtoupper($gateway->getCurrency())) {
            return match ($gateway->getNowPaymentsAmountUnit()) {
                'rial' => null !== $gateway->getNowPaymentsIrrToUsdRate(),
                default => null !== $gateway->getNowPaymentsTomanPerUsd(),
            };
        }

        return true;
    }
}
