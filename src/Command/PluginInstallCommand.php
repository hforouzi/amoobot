<?php

declare(strict_types=1);

namespace App\Command;

use App\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:install', description: 'Install a plugin ZIP package')]
final class PluginInstallCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('zipPath', InputArgument::REQUIRED, 'Path to the plugin ZIP package')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the plugin after installation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->pluginManager->installFromZip((string) $input->getArgument('zipPath'));

        if (!$result->success || null === $result->plugin) {
            $io->error($result->error ?? 'Plugin installation failed.');

            return Command::FAILURE;
        }

        if (true === $input->getOption('enable')) {
            $this->pluginManager->enable($result->plugin);
        }

        $io->success(sprintf(
            'Plugin %s installed%s.',
            $result->plugin->getCode(),
            true === $input->getOption('enable') ? ' and enabled' : ''
        ));

        return Command::SUCCESS;
    }
}
