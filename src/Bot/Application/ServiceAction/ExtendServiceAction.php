<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class ExtendServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_extend_menu:') || str_starts_with($callbackData, 'service_extend:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized service_extend actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        if (str_starts_with($context->data, 'service_extend_menu:')) {
            $serviceId = (int) str_replace('service_extend_menu:', '', $context->data);
            $this->serviceManagementService->showExtendMenu($serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $parts = explode(':', $context->data);
        if (3 !== count($parts)) {
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'عملیات نامعتبر است.', true);

            return;
        }

        $serviceId = (int) ($parts[1] ?? 0);
        $days = (int) ($parts[2] ?? 0);
        $this->serviceManagementService->extendService($serviceId, $days, $context->chatId, $context->callbackId);
    }
}
