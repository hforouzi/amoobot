<?php

declare(strict_types=1);

namespace App\Bot\UI;

use App\Bot\Application\BotMessageLogger;
use App\Bot\Application\TelegramUpdateHandler;
use App\Bot\Domain\BotMessageDirection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramUpdateHandler $telegramUpdateHandler,
        private readonly BotMessageLogger $botMessageLogger,
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/telegram/webhook/{secret}', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        if ($secret !== $this->webhookSecret) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid secret'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
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
