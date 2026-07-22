<?php

declare(strict_types=1);

namespace App\Command;

use App\Broadcast\BroadcastSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:broadcast:run', description: 'Prepare and send due scheduled/sending broadcasts')]
final class BroadcastRunCommand extends Command
{
    public function __construct(
        private readonly BroadcastSender $broadcastSender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max deliveries to send per broadcast', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show due work without saving or contacting Telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summary = $this->broadcastSender->runDue((int) $input->getOption('limit'), (bool) $input->getOption('dry-run'));

        $io->success((bool) $input->getOption('dry-run') ? 'Broadcast dry-run completed.' : 'Broadcast run completed.');
        $io->listing($this->summaryLines($summary->toArray()));

        return Command::SUCCESS;
    }

    private function summaryLines(array $summary): array
    {
        return array_map(static fn (string $key, int $value): string => sprintf('%s: %d', $key, $value), array_keys($summary), $summary);
    }
}
