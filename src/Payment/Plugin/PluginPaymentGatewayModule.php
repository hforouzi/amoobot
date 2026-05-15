<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Entity\Plugin;

final readonly class PluginPaymentGatewayModule
{
    /**
     * @param list<array<string, mixed>> $configSchema
     * @param list<string>              $permissions
     */
    public function __construct(
        public string $type,
        public string $pluginCode,
        public string $displayName,
        public string $description,
        public string $category,
        public string $version,
        public array $configSchema,
        public array $permissions,
        public string $mainClass,
        public string $path,
        public bool $supportsWebhook = false,
        public bool $supportsOnlinePayment = true,
        public bool $supportsManualConfirmation = false,
        public bool $implemented = true,
    ) {
    }

    public static function fromPlugin(Plugin $plugin, string $locale = 'en'): self
    {
        $manifest = $plugin->getManifest();
        $description = $plugin->getDescription();

        return new self(
            type: $plugin->getCode(),
            pluginCode: $plugin->getCode(),
            displayName: $plugin->getDisplayName($locale),
            description: is_array($description) ? (string) ($description[$locale] ?? $description['fa'] ?? $description['en'] ?? '') : '',
            category: (string) ($manifest['category'] ?? 'payment'),
            version: $plugin->getVersion(),
            configSchema: self::normalizeConfigSchema(is_array($manifest['configSchema'] ?? null) ? $manifest['configSchema'] : []),
            permissions: is_array($plugin->getPermissions()) ? $plugin->getPermissions() : [],
            mainClass: (string) ($plugin->getMainClass() ?? ''),
            path: $plugin->getPath(),
            supportsWebhook: true === ($manifest['supportsWebhook'] ?? false),
            supportsOnlinePayment: false !== ($manifest['supportsOnlinePayment'] ?? true),
            supportsManualConfirmation: true === ($manifest['supportsManualConfirmation'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'pluginCode' => $this->pluginCode,
            'displayName' => $this->displayName,
            'defaultTitle' => $this->displayName,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'source' => 'plugin',
            'isPlugin' => true,
            'supportsWebhook' => $this->supportsWebhook,
            'supportsOnlinePayment' => $this->supportsOnlinePayment,
            'supportsManualConfirmation' => $this->supportsManualConfirmation,
            'implemented' => $this->implemented,
            'defaults' => self::defaultsFromSchema($this->configSchema),
            'schema' => $this->configSchema,
            'configSchema' => $this->configSchema,
            'permissions' => $this->permissions,
            'mainClass' => $this->mainClass,
            'path' => $this->path,
        ];
    }


    /**
     * @param list<array<string, mixed>> $schema
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeConfigSchema(array $schema): array
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
                $normalizedField['choices'] = self::normalizeChoices(is_array($field['choices'] ?? null) ? $field['choices'] : []);
            }

            $normalized[] = $normalizedField;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $choices
     *
     * @return array<string, string|int|float|bool>
     */
    private static function normalizeChoices(array $choices): array
    {
        $normalized = [];
        foreach ($choices as $label => $value) {
            if (is_array($value)) {
                $choiceLabel = (string) ($value['label'] ?? '');
                $choiceValue = $value['value'] ?? null;
                if ('' === $choiceLabel || !is_scalar($choiceValue)) {
                    continue;
                }
                $normalized[$choiceLabel] = $choiceValue;
                continue;
            }

            if (is_scalar($value)) {
                $normalized[(string) $label] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $schema
     *
     * @return array<string, mixed>
     */
    private static function defaultsFromSchema(array $schema): array
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
}
