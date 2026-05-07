<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure;

use App\Bot\Application\BotMessageLogger;
use App\Bot\Domain\BotMessageDirection;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BotMessageLogger $botMessageLogger,
        private readonly string $botToken,
    ) {
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if (null !== $replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $this->callApi('sendMessage', $payload);
        } catch (\Throwable) {
        }
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'sendMessage', 'payload' => $payload], $chatId, 'message');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if (null !== $text) {
            $payload['text'] = $text;
        }

        try {
            $this->callApi('answerCallbackQuery', $payload);
        } catch (\Throwable) {
        }
    }

    public function sendPhoto(string $chatId, string $photoFileId, string $caption = '', ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photoFileId,
        ];

        if ('' !== trim($caption)) {
            $payload['caption'] = $caption;
        }

        if (null !== $replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $this->callApi('sendPhoto', $payload);
        } catch (\Throwable) {
        }
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'sendPhoto', 'payload' => $payload], $chatId, 'photo');
    }

    public function getUpdates(?int $offset = null, int $limit = 20, int $timeout = 25): array
    {
        $payload = [
            'limit' => $limit,
            'timeout' => $timeout,
        ];

        if (null !== $offset) {
            $payload['offset'] = $offset;
        }

        $data = $this->callApi('getUpdates', $payload);
        $result = $data['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        $payload = [];
        if ($dropPendingUpdates) {
            $payload['drop_pending_updates'] = true;
        }

        return $this->callApi('deleteWebhook', $payload);
    }

    public function getWebhookInfo(): array
    {
        return $this->callApi('getWebhookInfo', []);
    }

    private function callApi(string $method, array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method), [
                'json' => $payload,
            ]);
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface) {
            throw new \RuntimeException(sprintf('Telegram API transport error on method "%s".', $method));
        } catch (\Throwable) {
            throw new \RuntimeException(sprintf('Telegram API request failed on method "%s".', $method));
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Invalid Telegram API response on method "%s".', $method));
        }

        if (true !== ($data['ok'] ?? false)) {
            $description = (string) ($data['description'] ?? 'unknown error');
            throw new \RuntimeException(sprintf('Telegram API returned ok=false on method "%s": %s', $method, $description));
        }

        return $data;
    }
}
