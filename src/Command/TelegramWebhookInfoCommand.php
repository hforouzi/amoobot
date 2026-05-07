<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Infrastructure\TelegramApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:telegram:webhook-info', description: 'Show current Telegram webhook info')]
class TelegramWebhookInfoCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $data = $this->telegramApiClient->getWebhookInfo();
        } catch (\Throwable $e) {
            $io->error('getWebhookInfo failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: 'done');

        return Command::SUCCESS;
    }
}
