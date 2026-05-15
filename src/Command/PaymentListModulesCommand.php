<?php

declare(strict_types=1);

namespace App\Command;

use App\Payment\Application\PaymentGatewayModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:list-modules', description: 'List supported payment gateway modules')]
final class PaymentListModulesCommand extends Command
{
    public function __construct(
        private readonly PaymentGatewayModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($this->moduleRegistry->all() as $module) {
            $type = (string) $module['type'];
            $rows[] = [
                $type,
                (string) $module['displayName'],
                (string) ($module['source'] ?? 'core'),
                (string) ($module['version'] ?? ''),
                true === ($module['isPlugin'] ?? false) ? 'yes' : 'yes',
                (string) $module['category'],
                implode(', ', $this->moduleRegistry->requiredConfigFields($type)),
            ];
        }

        $io->table(['type', 'name', 'source', 'version', 'enabled', 'category', 'required_config_keys'], $rows);

        return Command::SUCCESS;
    }
}
