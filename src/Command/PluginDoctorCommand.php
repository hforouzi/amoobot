<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Plugin;
use App\Plugin\Driver\DriverDoctorResult;
use App\Plugin\Driver\DriverPluginDoctor;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:doctor', description: 'Explain whether a payment or driver plugin is usable')]
final class PluginDoctorCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry,
        private readonly PaymentPluginDoctor $doctor,
        private readonly DriverPluginDoctor $driverDoctor,
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
        $plugin = $this->pluginRegistry->findByCode((string) $input->getArgument('code'));
        if ($plugin instanceof Plugin && $plugin->isCore()) {
            $io->table(['field', 'value'], [
                ['plugin found', 'yes'],
                ['status', $plugin->getStatus()],
                ['type', $plugin->getType()],
                ['source', 'core (built in: no files to inspect)'],
            ]);
            $io->success('Core plugin row: built into the application.');

            return Command::SUCCESS;
        }
        if ($plugin instanceof Plugin && $plugin->isDriver()) {
            return $this->driver($io, $this->driverDoctor->inspect($plugin));
        }

        $result = $this->doctor->inspect($plugin);

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

    private function driver(SymfonyStyle $io, DriverDoctorResult $result): int
    {
        $io->table(['field', 'value'], [
            ['plugin found', $result->pluginFound ? 'yes' : 'no'],
            ['status', $result->status ?? '-'],
            ['type', $result->type ?? '-'],
            ['module', $result->module ?? '-'],
            ['source', 'package'],
            ['path', $result->path],
            ['mainClass', $result->mainClass],
            ['srcDir', $result->srcDir],
            ['requires core', '' === $result->coreRange ? '-' : $result->coreRange],
            ['core version', $result->coreVersion],
            ['core compatible', $result->coreOk ? 'yes' : 'no'],
            ['contract (package / core)', sprintf('%s / %s', $result->contractRequired ?? '-', $result->contractCore ?? '-')],
            ['contract compatible', $result->contractOk ? 'yes' : 'no'],
            ['file hashes match', $result->integrityOk ? 'yes' : 'no'],
            ['class_exists', $result->classExists ? 'yes' : 'no'],
            ['expected interface FQCN', '' === $result->expectedInterface ? '-' : $result->expectedInterface],
            ['implements interface', $result->implementsInterface ? 'yes' : 'no'],
            ['manifest capabilities', [] === $result->manifestCapabilities ? '-' : implode(', ', $result->manifestCapabilities)],
            ['driver capabilities', [] === $result->capabilities ? '-' : implode(', ', $result->capabilities)],
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
