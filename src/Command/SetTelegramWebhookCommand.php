<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Infrastructure\TelegramApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:telegram:set-webhook', description: 'Set Telegram webhook URL')]
class SetTelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly string $botToken,
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('baseUrl', InputArgument::REQUIRED, 'Base URL like https://mydomain.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseUrl = rtrim((string) $input->getArgument('baseUrl'), '/');
        $webhookUrl = sprintf('%s/telegram/webhook/%s', $baseUrl, $this->webhookSecret);

        $data = $this->telegramApiClient->setWebhook($webhookUrl);
        $output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: 'done');

        return Command::SUCCESS;
    }
}
