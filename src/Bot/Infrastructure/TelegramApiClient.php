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

        $this->callApi('sendMessage', $payload);
        $this->botMessageLogger->log(BotMessageDirection::OUTGOING, ['method' => 'sendMessage', 'payload' => $payload], $chatId, 'message');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if (null !== $text) {
            $payload['text'] = $text;
        }

        $this->callApi('answerCallbackQuery', $payload);
    }

    private function callApi(string $method, array $payload): void
    {
        try {
            $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method), [
                'json' => $payload,
            ]);
        } catch (TransportExceptionInterface) {
        }
    }
}
