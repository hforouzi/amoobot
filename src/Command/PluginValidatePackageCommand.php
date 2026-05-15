<?php

declare(strict_types=1);

namespace App\Command;

use App\Plugin\PluginInstallException;
use App\Plugin\PluginPackageValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:validate-package', description: 'Validate a plugin ZIP without installing it')]
final class PluginValidatePackageCommand extends Command
{
    public function __construct(
        private readonly PluginPackageValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('zipPath', InputArgument::REQUIRED, 'Path to plugin ZIP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $report = $this->validator->validate((string) $input->getArgument('zipPath'));
        } catch (PluginInstallException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $manifest = $report['manifest'];
        $doctor = $report['doctor'];
        $errors = $report['errors'];

        $io->section('Package validation');
        $io->table(['field', 'value'], [
            ['root', $report['root']],
            ['code', (string) ($manifest['code'] ?? '-')],
            ['type', (string) ($manifest['type'] ?? '-')],
            ['manifestVersion', (string) ($manifest['manifestVersion'] ?? '-')],
            ['mainClass', (string) ($manifest['mainClass'] ?? '-')],
            ['class_exists', null === $doctor ? '-' : ($doctor->classExists ? 'yes' : 'no')],
            ['implements interface', null === $doctor ? '-' : ($doctor->implementsInterface ? 'yes' : 'no')],
            ['configSchema valid', null === $doctor ? ([] === $errors ? 'yes' : 'no') : ($doctor->configSchemaValid ? 'yes' : 'no')],
            ['required config keys', null === $doctor || [] === $doctor->requiredConfigKeys ? '-' : implode(', ', $doctor->requiredConfigKeys)],
            ['errors', [] === $errors ? '-' : implode('; ', $errors)],
        ]);

        if ([] !== $errors) {
            $io->error('Plugin package is invalid.');

            return Command::FAILURE;
        }

        $io->success('Plugin package is valid.');

        return Command::SUCCESS;
    }
}
