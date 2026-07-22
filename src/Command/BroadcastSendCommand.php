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

#[AsCommand(name: 'app:broadcast:send', description: 'Send pending deliveries for a broadcast')]
final class BroadcastSendCommand extends Command
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max pending deliveries to send', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent without saving or contacting Telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $broadcast = $this->findBroadcast((int) $input->getArgument('broadcastId'));
        if (!$broadcast instanceof BroadcastMessage) {
            $io->error('Broadcast not found.');

            return Command::FAILURE;
        }

        if (0 === $this->broadcastSender->deliveryCount($broadcast)) {
            $this->broadcastSender->prepareDeliveries($broadcast, (bool) $input->getOption('dry-run'));
        }

        $summary = $this->broadcastSender->sendPending($broadcast, (int) $input->getOption('limit'), (bool) $input->getOption('dry-run'));
        $io->success((bool) $input->getOption('dry-run') ? 'Dry-run completed.' : 'Broadcast send batch completed.');
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
