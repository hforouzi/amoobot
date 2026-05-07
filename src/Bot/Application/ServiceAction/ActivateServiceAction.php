<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class ActivateServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_activate:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized service_activate actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        $serviceId = (int) str_replace('service_activate:', '', $context->data);
        $this->serviceManagementService->activateService($serviceId, $context->chatId, $context->callbackId);
    }
}
