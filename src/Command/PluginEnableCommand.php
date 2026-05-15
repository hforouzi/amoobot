<?php

declare(strict_types=1);

namespace App\Command;

use App\Plugin\PluginManager;
use App\Plugin\PluginRegistry;
use App\Entity\Plugin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:enable', description: 'Enable an installed plugin')]
final class PluginEnableCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginManager $pluginManager,
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
        $plugin = $this->registry->findByCode((string) $input->getArgument('code'));
        if (null === $plugin) {
            $io->error('Plugin not found.');

            return Command::FAILURE;
        }

        $this->pluginManager->enable($plugin);
        if (Plugin::STATUS_ERROR === $plugin->getStatus()) {
            $io->error(sprintf('Plugin %s failed validation: %s', $plugin->getCode(), (string) $plugin->getErrorMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Plugin %s enabled.', $plugin->getCode()));

        return Command::SUCCESS;
    }
}
