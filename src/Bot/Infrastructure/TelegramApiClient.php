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

    public function sendHtmlMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
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

    public function sendBroadcastMessageStrict(string $chatId, string $text, ?string $parseMode = null, bool $disableWebPagePreview = true): ?int
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => $disableWebPagePreview,
        ];

        if (null !== $parseMode && '' !== trim($parseMode)) {
            $payload['parse_mode'] = $parseMode;
        }

        $data = $this->callApi('sendMessage', $payload);
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'sendMessage', 'payload' => $payload], $chatId, 'message');

        $messageId = $data['result']['message_id'] ?? null;

        return null === $messageId ? null : (int) $messageId;
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

        $this->uploadFile('sendPhoto', 'photo', DataPart::fromPath($filePath), $chatId, $caption, $replyMarkup);

        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, [
            'method' => 'sendPhotoFile',
            'payload' => [
                'chat_id' => $chatId,
                'photo_path' => $filePath,
                'caption' => $caption,
            ],
        ], $chatId, 'photo');
    }

    /**
     * Sends a photo from disk and returns the file id Telegram stored it under (its largest size), so it can be sent
     * again by id like a photo the user sent to the bot. Null when Telegram did not accept it.
     */
    public function uploadPhoto(string $chatId, string $filePath, string $caption = ''): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }

        $payload = ['chat_id' => $chatId, 'photo' => DataPart::fromPath($filePath)];
        if ('' !== trim($caption)) {
            $payload['caption'] = $caption;
        }
        $formData = new FormDataPart($payload);
        $options = ['headers' => $formData->getPreparedHeaders()->toArray(), 'body' => $formData->bodyToIterable()];
        $proxy = trim($this->telegramProxy);
        if ('' !== $proxy) {
            $options['proxy'] = $proxy;
        }

        try {
            $data = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendPhoto', $this->botToken), $options)->toArray(false);
        } catch (\Throwable) {
            return null;
        }
        $photos = is_array($data['result']['photo'] ?? null) ? $data['result']['photo'] : [];
        $largest = end($photos);
        $fileId = is_array($largest) ? trim((string) ($largest['file_id'] ?? '')) : '';
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'uploadPhoto', 'payload' => ['chat_id' => $chatId, 'caption' => $caption]], $chatId, 'photo');

        return true === ($data['ok'] ?? false) && '' !== $fileId ? $fileId : null;
    }

    /**
     * Sends the file as is (Telegram does not recompress documents, unlike photos).
     */
    public function sendDocumentFile(string $chatId, string $filePath, string $caption = '', ?array $replyMarkup = null, ?string $fileName = null, ?string $contentType = null): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $this->uploadFile('sendDocument', 'document', DataPart::fromPath($filePath, $fileName, $contentType), $chatId, $caption, $replyMarkup);

        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, [
            'method' => 'sendDocumentFile',
            'payload' => [
                'chat_id' => $chatId,
                'document_path' => $filePath,
                'caption' => $caption,
            ],
        ], $chatId, 'document');
    }

    private function uploadFile(string $method, string $field, DataPart $file, string $chatId, string $caption, ?array $replyMarkup): void
    {
        $payload = [
            'chat_id' => $chatId,
            $field => $file,
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
                sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method),
                $options
            );
            $data = $response->toArray(false);
            if (!is_array($data) || true !== ($data['ok'] ?? false)) {
                throw new \RuntimeException(sprintf('Telegram %s file request returned non-ok response.', $method));
            }
        } catch (\Throwable) {
        }
    }

    /**
     * The content of a file the bot received (e.g. a receipt photo), downloaded by the server: getFile, then the file
     * from Telegram's file endpoint. Null when it is unknown, larger than $maxBytes or Telegram cannot be reached. The
     * download URL carries the bot token, so it never leaves this method.
     */
    public function downloadFile(string $fileId, int $maxBytes): ?string
    {
        try {
            $file = $this->callApi('getFile', ['file_id' => $fileId])['result'] ?? null;
            $path = is_array($file) ? trim((string) ($file['file_path'] ?? '')) : '';
            if ('' === $path || (int) ($file['file_size'] ?? 0) > $maxBytes) {
                return null;
            }

            $options = [];
            $proxy = trim($this->telegramProxy);
            if ('' !== $proxy) {
                $options['proxy'] = $proxy;
            }
            $response = $this->httpClient->request('GET', sprintf('https://api.telegram.org/file/bot%s/%s', $this->botToken, $path), $options);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $content = $response->getContent();

            return strlen($content) > $maxBytes ? null : $content;
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * @param string|null $secretToken echoed back by Telegram in the X-Telegram-Bot-Api-Secret-Token header
     */
    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = ['url' => $url];
        if (null !== $secretToken && '' !== $secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return $this->callApi('setWebhook', $payload);
    }

    /**
     * The bot's default menu button (all private chats), e.g. ['type' => 'web_app', 'text' => ..., 'web_app' => ['url' => ...]]
     * or ['type' => 'default'].
     *
     * @param array<string, mixed> $menuButton
     */
    public function setChatMenuButton(array $menuButton): array
    {
        return $this->callApi('setChatMenuButton', ['menu_button' => $menuButton]);
    }

    /**
     * The bot's own user (id, username, ...).
     *
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        $result = $this->callApi('getMe', [])['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    public function getWebhookInfo(): array
    {
        return $this->callApi('getWebhookInfo', []);
    }

    public function getChatMember(string $chatId, int|string $userId): array
    {
        $data = $this->callApi('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => (string) $userId,
        ]);
        $result = $data['result'] ?? [];

        return is_array($result) ? $result : [];
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
