<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Plugin;
use App\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plugin:list', description: 'List installed plugins')]
final class PluginListCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plugins = $this->registry->all();

        if ([] === $plugins) {
            $io->warning('No plugins installed.');

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (Plugin $plugin): array => [
            $plugin->getCode(),
            $plugin->getType(),
            $plugin->getDisplayName('en'),
            $plugin->getVersion(),
            $plugin->getStatus(),
            $plugin->getPath(),
        ], $plugins);

        $io->table(['code', 'type', 'name', 'version', 'status', 'path'], $rows);

        return Command::SUCCESS;
    }
}
