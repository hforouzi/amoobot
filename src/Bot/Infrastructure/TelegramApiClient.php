<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure;

use App\Bot\Application\BotMessageLogger;
use App\Bot\Domain\BotMessageDirection;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BotMessageLogger $botMessageLogger,
        private readonly string $botToken,
        private readonly string $telegramProxy = '',
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

    public function sendMessageStrict(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if (null !== $replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $this->callApi('sendMessage', $payload);
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'sendMessage', 'payload' => $payload], $chatId, 'message');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if (null !== $text) {
            $payload['text'] = $text;
        }
        if ($showAlert) {
            $payload['show_alert'] = true;
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

    public function sendPhotoFile(string $chatId, string $filePath, string $caption = '', ?array $replyMarkup = null): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'photo' => DataPart::fromPath($filePath),
        ];

        if ('' !== trim($caption)) {
            $payload['caption'] = $caption;
        }

        if (null !== $replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $formData = new FormDataPart($payload);
        $options = [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
        ];

        $proxy = trim($this->telegramProxy);
        if ('' !== $proxy) {
            $options['proxy'] = $proxy;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, 'sendPhoto'),
                $options
            );
            $data = $response->toArray(false);
            if (!is_array($data) || true !== ($data['ok'] ?? false)) {
                throw new \RuntimeException('Telegram sendPhoto file request returned non-ok response.');
            }
        } catch (\Throwable) {
        }

        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, [
            'method' => 'sendPhotoFile',
            'payload' => [
                'chat_id' => $chatId,
                'photo_path' => $filePath,
                'caption' => $caption,
            ],
        ], $chatId, 'photo');
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

    public function setWebhook(string $url): array
    {
        return $this->callApi('setWebhook', ['url' => $url]);
    }

    public function getWebhookInfo(): array
    {
        return $this->callApi('getWebhookInfo', []);
    }

    private function callApi(string $method, array $payload): array
    {
        $options = ['json' => $payload];
        $proxy = trim($this->telegramProxy);
        if ('' !== $proxy) {
            $options['proxy'] = $proxy;
        }

        try {
            $response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method), $options);
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

    public function isProxyEnabled(): bool
    {
        return '' !== trim($this->telegramProxy);
    }

    public function proxyType(): string
    {
        $proxy = trim($this->telegramProxy);
        if ('' === $proxy) {
            return 'none';
        }

        if (str_starts_with($proxy, 'socks5://')) {
            return 'socks5';
        }

        if (str_starts_with($proxy, 'http://') || str_starts_with($proxy, 'https://')) {
            return 'http';
        }

        return 'unknown';
    }
}
