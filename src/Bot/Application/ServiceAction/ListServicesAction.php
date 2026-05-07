<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class ListServicesAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return 'my_services' === $callbackData || 'admin_services' === $callbackData;
    }

    public function handle(ServiceActionContext $context): void
    {
        if ('my_services' === $context->data) {
            $this->serviceManagementService->handleMyServices($context->account, $context->chatId, $context->callbackId);

            return;
        }

        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized admin_services actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        $this->serviceManagementService->handleAdminServices($context->chatId, $context->callbackId);
    }
}
