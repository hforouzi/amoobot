<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\Form\ConfigSchemaChoiceNormalizer;
use App\Payment\Application\PaymentGatewayModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:debug-choice-schemas', description: 'Validate and print payment gateway choice schema fields')]
final class PaymentDebugChoiceSchemasCommand extends Command
{
    public function __construct(
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
        private readonly ConfigSchemaChoiceNormalizer $choiceNormalizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        $invalidCount = 0;

        foreach ($this->moduleRegistry->all() as $module) {
            $moduleType = (string) ($module['type'] ?? 'unknown');
            $schema = is_array($module['schema'] ?? null) ? $module['schema'] : [];

            foreach ($schema as $index => $field) {
                if (!is_array($field) || 'choice' !== (string) ($field['type'] ?? '')) {
                    continue;
                }

                $fieldKey = (string) ($field['name'] ?? ('field_'.$index));
                $rawChoices = $field['choices'] ?? [];
                $normalized = $this->choiceNormalizer->normalize($rawChoices, sprintf('%s.%s', $moduleType, $fieldKey));
                $valid = is_array($rawChoices) && count($normalized) === count($rawChoices);
                if (!$valid) {
                    ++$invalidCount;
                }

                $rows[] = [
                    $moduleType,
                    $fieldKey,
                    $valid ? 'yes' : 'no',
                    json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        if ([] === $rows) {
            $io->warning('No choice schema fields found.');

            return Command::SUCCESS;
        }

        $io->table(['module', 'field', 'valid', 'normalized choices'], $rows);

        if ($invalidCount > 0) {
            $io->error(sprintf('%d invalid choice schema field(s) detected.', $invalidCount));

            return Command::FAILURE;
        }

        $io->success('All payment gateway choice schema fields are valid.');

        return Command::SUCCESS;
    }
}

