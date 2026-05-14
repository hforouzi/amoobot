<?php

declare(strict_types=1);

namespace App\Payment\Application;

use Psr\Log\LoggerInterface;

final class ConfigSchemaChoiceNormalizer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $choices
     *
     * @return array<string, string|int>
     */
    public function normalizeChoices(array $choices, string $fieldKey = ''): array
    {
        $normalized = [];

        foreach ($choices as $label => $value) {
            if (is_array($value) && array_key_exists('label', $value)) {
                $entryLabel = $value['label'];
                $entryValue = $value['value'] ?? null;
            } else {
                $entryLabel = is_string($label) ? $label : $value;
                $entryValue = $value;
            }

            if (!is_scalar($entryLabel) || !is_scalar($entryValue)) {
                $this->logger->warning('payment.config_schema.choice_skipped_invalid', [
                    'field' => $fieldKey,
                    'label_type' => get_debug_type($entryLabel),
                    'value_type' => get_debug_type($entryValue),
                ]);
                continue;
            }

            $choiceValue = is_bool($entryValue) ? (int) $entryValue : $entryValue;
            $choiceLabel = trim((string) $entryLabel);

            if ('' === $choiceLabel) {
                $choiceLabel = (string) $choiceValue;
            }

            if ('' === $choiceLabel) {
                $this->logger->warning('payment.config_schema.choice_skipped_empty_label', [
                    'field' => $fieldKey,
                ]);
                continue;
            }

            $normalized[$choiceLabel] = $choiceValue;
        }

        return $normalized;
    }
}

