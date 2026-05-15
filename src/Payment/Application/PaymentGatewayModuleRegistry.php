<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Admin\Form\ConfigSchemaChoiceNormalizer;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Plugin\PluginRegistry;

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
     *   schema: list<array{name: string, type: string, required: bool, default?: mixed, choices?: array<string, string|int>}>,
     *   configSchema: list<array{name: string, type: string, required: bool, default?: mixed, choices?: array<string, string|int>}>
     * }>
     */
    private array $modules;

    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly ConfigSchemaChoiceNormalizer $choiceNormalizer,
        private readonly PluginRegistry $pluginRegistry,
    ) {
        $this->modules = [
            PaymentGatewayType::MANUAL_CARD => [
                'type' => PaymentGatewayType::MANUAL_CARD,
                'displayName' => 'Manual Card / کارت به کارت',
                'defaultTitle' => 'کارت به کارت',
                'description' => 'ثبت اطلاعات کارت و نمایش راهنمای پرداخت دستی برای کاربر.',
                'category' => 'offline',
                'supportsWebhook' => false,
                'supportsOnlinePayment' => false,
                'source' => 'core',
                'isPlugin' => false,
                'version' => '',
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
                'displayName' => 'Zibal / زیبال',
                'defaultTitle' => 'زیبال',
                'description' => 'درگاه آنلاین ریالی زیبال برای پرداخت مستقیم کاربر.',
                'category' => 'online',
                'supportsWebhook' => true,
                'supportsOnlinePayment' => true,
                'source' => 'core',
                'isPlugin' => false,
                'version' => '',
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
                'source' => 'core',
                'isPlugin' => false,
                'version' => '',
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
                    [
                        'name' => 'payment_mode',
                        'type' => 'choice',
                        'required' => true,
                        'default' => 'invoice',
                        'choices' => [
                            'Invoice / صفحه پرداخت' => 'invoice',
                            'Direct Payment / پرداخت مستقیم' => 'payment',
                        ],
                    ],
                    ['name' => 'price_currency', 'type' => 'text', 'required' => true, 'default' => 'usd'],
                    ['name' => 'pay_currency', 'type' => 'text', 'required' => false, 'default' => 'usdttrc20'],
                    [
                        'name' => 'amount_unit',
                        'type' => 'choice',
                        'required' => false,
                        'default' => 'toman',
                        'choices' => [
                            'Toman / تومان' => 'toman',
                            'Rial / ریال' => 'rial',
                        ],
                    ],
                    ['name' => 'toman_per_usd', 'type' => 'integer', 'required' => true],
                    ['name' => 'callback_base_url', 'type' => 'text', 'required' => true],
                    ['name' => 'success_url', 'type' => 'text', 'required' => false],
                    ['name' => 'cancel_url', 'type' => 'text', 'required' => false],
                ],
            ],
        ];

        $this->normalizeSchemaChoices();
        $this->syncConfigSchemas();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->modules + $this->pluginModulesByType());
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_keys($this->modules + $this->pluginModulesByType());
    }

    public function supports(string $type): bool
    {
        return isset($this->modules[$type]) || isset($this->pluginModulesByType()[$type]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $type): array
    {
        if (!$this->supports($type)) {
            throw new \InvalidArgumentException(sprintf('Unsupported payment gateway module "%s".', $type));
        }

        return $this->modules[$type] ?? $this->pluginModulesByType()[$type];
    }

    /**
     * @return array<string, string>
     */
    public function choiceMap(): array
    {
        return $this->choiceNormalizer->normalize([
            'کارت به کارت' => PaymentGatewayType::MANUAL_CARD,
            'زیبال' => PaymentGatewayType::ZIBAL,
            'NOWPayments' => PaymentGatewayType::NOWPAYMENTS,
        ], 'gateway.type');
    }

    public function displayName(string $type): string
    {
        $modules = $this->modules + $this->pluginModulesByType();

        return (string) ($modules[$type]['displayName'] ?? $type);
    }

    public function defaultTitle(string $type): string
    {
        $modules = $this->modules + $this->pluginModulesByType();

        return (string) ($modules[$type]['defaultTitle'] ?? $this->displayName($type));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(string $type): array
    {
        $modules = $this->modules + $this->pluginModulesByType();

        return (array) ($modules[$type]['defaults'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function requiredConfigFields(string $type): array
    {
        $required = [];
        $modules = $this->modules + $this->pluginModulesByType();
        foreach ((array) ($modules[$type]['schema'] ?? []) as $field) {
            if (true === ($field['required'] ?? false)) {
                $required[] = (string) ($field['name'] ?? $field['key'] ?? '');
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
            if (isset($this->pluginModulesByType()[$type])) {
                return true;
            }
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
            default => $this->isPluginGatewayConfigured($gateway),
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

    private function normalizeSchemaChoices(): void
    {
        foreach ($this->modules as $moduleType => $module) {
            $schema = is_array($module['schema'] ?? null) ? $module['schema'] : [];

            foreach ($schema as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                if ('choice' !== (string) ($field['type'] ?? '')) {
                    continue;
                }

                $fieldName = (string) ($field['name'] ?? ('field_'.$index));
                $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
                $schema[$index]['choices'] = $this->choiceNormalizer->normalize(
                    $choices,
                    sprintf('%s.%s', $moduleType, $fieldName)
                );
            }

            $this->modules[$moduleType]['schema'] = $schema;
        }
    }

    private function syncConfigSchemas(): void
    {
        foreach ($this->modules as $moduleType => $module) {
            $this->modules[$moduleType]['configSchema'] = is_array($module['schema'] ?? null) ? $module['schema'] : [];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pluginModulesByType(): array
    {
        $modules = [];
        foreach ($this->pluginRegistry->paymentGatewayPlugins() as $plugin) {
            $manifest = $plugin->getManifest();
            $schema = $this->normalizePluginSchema(is_array($manifest['configSchema'] ?? null) ? $manifest['configSchema'] : []);
            $description = $plugin->getDescription();
            $modules[$plugin->getCode()] = [
                'type' => $plugin->getCode(),
                'pluginCode' => $plugin->getCode(),
                'displayName' => $plugin->getDisplayName('en'),
                'defaultTitle' => $plugin->getDisplayName('en'),
                'description' => is_array($description) ? (string) ($description['en'] ?? $description['fa'] ?? '') : '',
                'category' => (string) ($manifest['category'] ?? 'payment'),
                'supportsWebhook' => true === ($manifest['supportsWebhook'] ?? false),
                'supportsOnlinePayment' => false !== ($manifest['supportsOnlinePayment'] ?? true),
                'supportsManualConfirmation' => true === ($manifest['supportsManualConfirmation'] ?? false),
                'source' => 'plugin',
                'isPlugin' => true,
                'version' => $plugin->getVersion(),
                'defaults' => $this->defaultsFromSchema($schema),
                'schema' => $schema,
                'configSchema' => $schema,
                'permissions' => is_array($plugin->getPermissions()) ? $plugin->getPermissions() : [],
                'mainClass' => (string) ($plugin->getMainClass() ?? ''),
                'path' => $plugin->getPath(),
                'implemented' => true,
            ];
        }

        return $modules;
    }

    /**
     * @param list<array<string, mixed>> $schema
     *
     * @return list<array<string, mixed>>
     */
    private function normalizePluginSchema(array $schema): array
    {
        $normalized = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? $field['key'] ?? ''));
            if ('' === $name) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, ['text', 'password', 'textarea', 'boolean', 'number', 'integer', 'url', 'choice'], true)) {
                $type = 'text';
            }

            $normalizedField = $field;
            $normalizedField['name'] = $name;
            $normalizedField['type'] = $type;
            $normalizedField['required'] = true === ($field['required'] ?? false);
            unset($normalizedField['key']);

            if ('choice' === $type) {
                $normalizedField['choices'] = $this->choiceNormalizer->normalize(
                    is_array($field['choices'] ?? null) ? $field['choices'] : [],
                    $name
                );
            }

            $normalized[] = $normalizedField;
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $schema
     *
     * @return array<string, mixed>
     */
    private function defaultsFromSchema(array $schema): array
    {
        $defaults = [];
        foreach ($schema as $field) {
            $name = (string) ($field['name'] ?? '');
            if ('' !== $name && array_key_exists('default', $field)) {
                $defaults[$name] = $field['default'];
            }
        }

        return $defaults;
    }

    private function isPluginGatewayConfigured(PaymentGateway $gateway): bool
    {
        $module = $this->pluginModulesByType()[$gateway->getType()] ?? null;
        if (!is_array($module)) {
            return false;
        }

        $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
        foreach ((array) ($module['configSchema'] ?? []) as $field) {
            if (!is_array($field) || true !== ($field['required'] ?? false)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
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
}
