<?php

declare(strict_types=1);

namespace App\Bot\UI;

use App\Bot\Application\BotMessageLogger;
use App\Bot\Application\TelegramChatScope;
use App\Bot\Application\TelegramUpdateHandler;
use App\Bot\Domain\BotMessageDirection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    public const SECRET_TOKEN_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __construct(
        private readonly TelegramUpdateHandler $telegramUpdateHandler,
        private readonly BotMessageLogger $botMessageLogger,
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecretToken = '',
    ) {
    }

    /**
     * The secret_token registered with setWebhook: TELEGRAM_WEBHOOK_SECRET_TOKEN, or the URL secret when unset.
     */
    public static function effectiveSecretToken(string $webhookSecretToken, string $webhookSecret): string
    {
        return '' !== $webhookSecretToken ? $webhookSecretToken : $webhookSecret;
    }

    #[Route('/telegram/webhook/{secret}', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        if ('' === $this->webhookSecret || !hash_equals($this->webhookSecret, $secret)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid secret'], 403);
        }

        $expectedToken = self::effectiveSecretToken($this->webhookSecretToken, $this->webhookSecret);
        $headerToken = (string) $request->headers->get(self::SECRET_TOKEN_HEADER, '');
        if (!hash_equals($expectedToken, $headerToken)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid secret'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        // Private chats only (TelegramChatScope): an update of a group, supergroup or channel is not even logged.
        if (!is_array($payload) || TelegramChatScope::isOutsidePrivateChats($payload)) {
            return new JsonResponse(['ok' => true]);
        }

        $telegramId = $payload['message']['from']['id'] ?? $payload['callback_query']['from']['id'] ?? null;
        $updateType = isset($payload['callback_query']) ? 'callback_query' : (isset($payload['message']) ? 'message' : null);

        $this->botMessageLogger->log(
            BotMessageDirection::INCOMING,
            $payload,
            null !== $telegramId ? (string) $telegramId : null,
            $updateType
        );

        try {
            $this->telegramUpdateHandler->handle($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Telegram webhook handler failed', [
                'exception' => $e,
                'update_id' => $payload['update_id'] ?? null,
                'payload_keys' => array_keys($payload),
            ]);
        }

        return new JsonResponse(['ok' => true]);
    }
}
