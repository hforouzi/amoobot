<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\BotMessageLogger;
use App\Bot\Application\TelegramUpdateHandler;
use App\Bot\Domain\BotMessageDirection;
use App\Bot\Infrastructure\TelegramApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:telegram:poll', description: 'Poll Telegram updates using long polling')]
class TelegramPollCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramUpdateHandler $telegramUpdateHandler,
        private readonly BotMessageLogger $botMessageLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Telegram getUpdates limit', '20')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Telegram getUpdates timeout', '25')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep seconds between empty/error polls', '1')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run only one getUpdates request')
            ->addOption('drop-pending', null, InputOption::VALUE_NONE, 'Drop pending updates on startup')
            ->addOption('no-delete-webhook', null, InputOption::VALUE_NONE, 'Do not call deleteWebhook on startup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = max(0, (int) $input->getOption('timeout'));
        $sleepSeconds = max(0.0, (float) $input->getOption('sleep'));
        $once = (bool) $input->getOption('once');
        $dropPending = (bool) $input->getOption('drop-pending');
        $noDeleteWebhook = (bool) $input->getOption('no-delete-webhook');

        $offset = null;

        if (!$noDeleteWebhook) {
            try {
                $this->telegramApiClient->deleteWebhook($dropPending);
                $io->writeln($dropPending ? 'Webhook deleted (drop pending updates: true).' : 'Webhook deleted.');
            } catch (\Throwable $exception) {
                $io->warning('Failed to delete webhook: '.$this->safeMessage($exception));
            }
        } elseif ($dropPending) {
            $io->writeln('Skipping pending updates without deleting webhook...');
            try {
                $pending = $this->telegramApiClient->getUpdates(null, 100, 0);
                $offset = $this->computeNextOffset($pending);
                $io->writeln(sprintf('Skipped %d pending update(s).', count($pending)));
            } catch (\Throwable $exception) {
                $io->warning('Failed to skip pending updates: '.$this->safeMessage($exception));
            }
        }

        $io->writeln(sprintf('Polling started (limit=%d, timeout=%d, sleep=%s, once=%s).', $limit, $timeout, (string) $sleepSeconds, $once ? 'true' : 'false'));

        do {
            try {
                $updates = $this->telegramApiClient->getUpdates($offset, $limit, $timeout);
            } catch (\Throwable $exception) {
                $io->error('getUpdates failed: '.$this->safeMessage($exception));
                $this->sleep($sleepSeconds);
                continue;
            }

            $io->writeln(sprintf('Received %d update(s).', count($updates)));
            if ([] === $updates) {
                $this->sleep($sleepSeconds);
                continue;
            }

            foreach ($updates as $update) {
                if (!is_array($update)) {
                    continue;
                }

                $updateId = (int) ($update['update_id'] ?? 0);
                if ($updateId > 0) {
                    $offset = $updateId + 1;
                }

                $io->writeln(sprintf('Processing update_id=%d', $updateId));
                if (isset($update['callback_query']['data'])) {
                    $io->writeln(sprintf('callback_data=%s', (string) $update['callback_query']['data']));
                }
                $this->logIncoming($update);

                try {
                    $this->telegramUpdateHandler->handle($update);
                } catch (\Throwable $exception) {
                    $io->warning(sprintf('Handler failed for update_id=%d: %s', $updateId, $this->safeMessage($exception)));
                }
            }
        } while (!$once);

        return Command::SUCCESS;
    }

    private function computeNextOffset(array $updates): ?int
    {
        $lastUpdateId = null;
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $updateId = (int) ($update['update_id'] ?? 0);
            if ($updateId > 0) {
                $lastUpdateId = $updateId;
            }
        }

        return null === $lastUpdateId ? null : $lastUpdateId + 1;
    }

    private function logIncoming(array $update): void
    {
        $telegramId = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
        $updateType = isset($update['callback_query']) ? 'callback_query' : (isset($update['message']) ? 'message' : null);

        try {
            $this->botMessageLogger->log(
                BotMessageDirection::INCOMING,
                $update,
                null !== $telegramId ? (string) $telegramId : null,
                $updateType
            );
        } catch (\Throwable) {
        }
    }

    private function safeMessage(\Throwable $exception): string
    {
        return trim($exception->getMessage()) ?: 'unknown error';
    }

    private function sleep(float $sleepSeconds): void
    {
        if ($sleepSeconds <= 0.0) {
            return;
        }

        usleep((int) round($sleepSeconds * 1_000_000));
    }
}
