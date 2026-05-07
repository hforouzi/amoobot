<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Infrastructure\TelegramApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:telegram:mode-info', description: 'Show current Telegram mode and proxy configuration')]
class TelegramModeInfoCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly string $telegramMode = 'long_polling',
        private readonly string $webhookSecret = '',
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Telegram Mode Info');

        $io->listing([
            sprintf('TELEGRAM_MODE: %s', $this->telegramMode),
            sprintf('proxy enabled: %s', $this->telegramApiClient->isProxyEnabled() ? 'yes' : 'no'),
            sprintf('proxy type: %s', $this->telegramApiClient->proxyType()),
        ]);

        $io->section('Reminders');
        $io->writeln('- webhook requires a public HTTPS URL reachable by Telegram servers.');
        $io->writeln('- long_polling does not require a public domain; use TELEGRAM_PROXY for restricted networks.');
        $io->writeln('- TELEGRAM_PROXY applies only to Bot → Telegram API (outgoing). Webhook inbound requests cannot be proxied.');

        return Command::SUCCESS;
    }
}
