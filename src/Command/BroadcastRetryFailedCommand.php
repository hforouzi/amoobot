<?php

declare(strict_types=1);

namespace App\Command;

use App\Broadcast\BroadcastSender;
use App\Entity\BroadcastMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:broadcast:retry-failed', description: 'Retry failed deliveries for a broadcast')]
final class BroadcastRetryFailedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BroadcastSender $broadcastSender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('broadcastId', InputArgument::REQUIRED, 'BroadcastMessage id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max failed deliveries to retry', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $broadcast = $this->findBroadcast((int) $input->getArgument('broadcastId'));
        if (!$broadcast instanceof BroadcastMessage) {
            $io->error('Broadcast not found.');

            return Command::FAILURE;
        }

        $summary = $this->broadcastSender->retryFailed($broadcast, (int) $input->getOption('limit'));
        $io->success('Failed broadcast deliveries retried.');
        $io->listing($this->summaryLines($summary->toArray()));

        return Command::SUCCESS;
    }

    private function findBroadcast(int $id): ?BroadcastMessage
    {
        $broadcast = $this->entityManager->getRepository(BroadcastMessage::class)->find($id);

        return $broadcast instanceof BroadcastMessage ? $broadcast : null;
    }

    private function summaryLines(array $summary): array
    {
        return array_map(static fn (string $key, int $value): string => sprintf('%s: %d', $key, $value), array_keys($summary), $summary);
    }
}
