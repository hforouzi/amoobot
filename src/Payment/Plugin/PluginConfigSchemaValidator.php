<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Admin\Form\ConfigSchemaChoiceNormalizer;
use App\Plugin\ValidationResult;

final class PluginConfigSchemaValidator
{
    private const SUPPORTED_TYPES = ['text', 'password', 'textarea', 'boolean', 'number', 'integer', 'url', 'choice'];

    public function __construct(
        private readonly ConfigSchemaChoiceNormalizer $choiceNormalizer,
    ) {
    }

    /**
     * @param mixed $schema
     */
    public function validate(mixed $schema): ValidationResult
    {
        $errors = [];
        if (null === $schema) {
            return ValidationResult::valid();
        }

        if (!is_array($schema)) {
            return ValidationResult::invalid(['configSchema must be an array.']);
        }

        $seen = [];
        foreach ($schema as $index => $field) {
            if (!is_array($field)) {
                $errors[] = sprintf('configSchema[%d] must be an object.', (int) $index);
                continue;
            }

            $name = trim((string) ($field['name'] ?? $field['key'] ?? ''));
            if ('' === $name) {
                $errors[] = sprintf('configSchema[%d] must define name or key.', (int) $index);
                continue;
            }

            if (isset($seen[$name])) {
                $errors[] = sprintf('configSchema contains duplicate key "%s".', $name);
            }
            $seen[$name] = true;

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $errors[] = sprintf('configSchema.%s has unsupported type "%s".', $name, $type);
            }

            if (array_key_exists('required', $field) && !is_bool($field['required'])) {
                $errors[] = sprintf('configSchema.%s required must be boolean.', $name);
            }

            if ('choice' === $type) {
                $choices = $this->choiceNormalizer->normalize($field['choices'] ?? null, $name);
                if ([] === $choices) {
                    $errors[] = sprintf('configSchema.%s choice field must define at least one valid choice.', $name);
                }
            }
        }

        return [] === $errors ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    /**
     * @param mixed $schema
     *
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? $field['key'] ?? ''));
            if ('' === $name || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $type = 'text';
            }

            $normalizedField = $field;
            unset($normalizedField['key']);
            $normalizedField['name'] = $name;
            $normalizedField['type'] = $type;
            $normalizedField['required'] = true === ($field['required'] ?? false);

            if ('choice' === $type) {
                $normalizedField['choices'] = $this->choiceNormalizer->normalize($field['choices'] ?? [], $name);
            }

            $normalized[] = $normalizedField;
        }

        return $normalized;
    }

    /**
     * @param mixed $schema
     *
     * @return list<string>
     */
    public function requiredKeys(mixed $schema): array
    {
        $keys = [];
        foreach ($this->normalize($schema) as $field) {
            if (true === ($field['required'] ?? false)) {
                $keys[] = (string) $field['name'];
            }
        }

        return $keys;
    }

    /**
     * @param mixed $schema
     *
     * @return array<string, mixed>
     */
    public function defaultConfig(mixed $schema): array
    {
        $defaults = [];
        foreach ($this->normalize($schema) as $field) {
            if (array_key_exists('default', $field)) {
                $defaults[(string) $field['name']] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * @param mixed $schema
     * @param array<string, mixed>|null $config
     *
     * @return list<string>
     */
    public function missingRequiredKeys(mixed $schema, ?array $config): array
    {
        $config ??= [];
        $missing = [];
        foreach ($this->requiredKeys($schema) as $key) {
            $value = $config[$key] ?? null;
            if (null === $value || (is_string($value) && '' === trim($value))) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param mixed $schema
     * @param array<string, mixed>|null $config
     */
    public function isConfigured(mixed $schema, ?array $config): bool
    {
        return [] === $this->missingRequiredKeys($schema, $config);
    }
}
