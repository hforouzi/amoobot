<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:telegram:set-webhook', description: 'Set Telegram webhook URL')]
class SetTelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
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

        $response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/setWebhook', $this->botToken), [
            'json' => ['url' => $webhookUrl],
        ]);

        $data = $response->toArray(false);
        $output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: 'done');

        return Command::SUCCESS;
    }
}
