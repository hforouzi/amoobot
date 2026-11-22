<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Application\ModuleCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:product:list-modules', description: 'List product modules and provider drivers (core and packages)')]
final class ProductListModulesCommand extends Command
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = array_map(static fn (array $row): array => array_values($row), $this->catalog->rows());

        $io->table(['kind', 'module', 'code', 'source', 'version', 'contract', 'status', 'allowed', 'capabilities'], $rows);

        return Command::SUCCESS;
    }
}
