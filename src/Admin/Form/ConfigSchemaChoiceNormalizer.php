<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Psr\Log\LoggerInterface;

final class ConfigSchemaChoiceNormalizer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string|int>
     */
    public function normalize(mixed $choices, string $fieldKey = ''): array
    {
        if (!is_array($choices)) {
            return [];
        }

        $normalized = [];

        foreach ($choices as $label => $value) {
            $entryLabel = $label;
            $entryValue = $value;

            if (is_array($value)) {
                $entryLabel = $value['label'] ?? $value['name'] ?? $label;
                $entryValue = $value['value'] ?? $value['type'] ?? null;
            }

            if (!is_scalar($entryLabel) || !is_scalar($entryValue)) {
                $this->logger->warning('invalid_choice_skipped', [
                    'field' => $fieldKey,
                    'type' => get_debug_type($entryValue),
                ]);
                continue;
            }

            if (is_bool($entryValue)) {
                $entryValue = $entryValue ? '1' : '0';
            }

            $choiceLabel = trim((string) $entryLabel);
            if ('' === $choiceLabel) {
                $choiceLabel = (string) $entryValue;
            }

            if ('' === $choiceLabel) {
                $this->logger->warning('invalid_choice_skipped', [
                    'field' => $fieldKey,
                    'type' => 'empty_label',
                ]);
                continue;
            }

            $normalized[$choiceLabel] = is_int($entryValue) ? $entryValue : (string) $entryValue;
        }

        return $normalized;
    }
}

