<?php

declare(strict_types=1);

namespace App\Command;

use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:doctor', description: 'Explain whether a payment plugin is usable')]
final class PluginDoctorCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry,
        private readonly PaymentPluginDoctor $doctor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::REQUIRED, 'Plugin code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->doctor->inspect($this->pluginRegistry->findByCode((string) $input->getArgument('code')));

        $io->table(['field', 'value'], [
            ['plugin found', $result->pluginFound ? 'yes' : 'no'],
            ['status', $result->status ?? '-'],
            ['type', $result->type ?? '-'],
            ['path', $result->path],
            ['mainClass', $result->mainClass],
            ['namespace prefix', $result->namespacePrefix],
            ['srcDir', $result->srcDir],
            ['class file candidate', $result->classFileCandidate],
            ['class_exists', $result->classExists ? 'yes' : 'no'],
            ['expected interface FQCN', $result->expectedInterface],
            ['implements interface', $result->implementsInterface ? 'yes' : 'no'],
            ['configSchema valid', $result->configSchemaValid ? 'yes' : 'no'],
            ['required config keys', [] === $result->requiredConfigKeys ? '-' : implode(', ', $result->requiredConfigKeys)],
            ['errors', [] === $result->errors ? '-' : implode('; ', $result->errors)],
        ]);

        if (!$result->ok()) {
            $io->error('Plugin is not usable.');

            return Command::FAILURE;
        }

        $io->success('Plugin is usable.');

        return Command::SUCCESS;
    }
}
