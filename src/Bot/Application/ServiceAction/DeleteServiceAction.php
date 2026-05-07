<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class DeleteServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_delete:')
            || str_starts_with($callbackData, 'service_delete_confirm:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized service_delete actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        if (str_starts_with($context->data, 'service_delete_confirm:')) {
            $serviceId = (int) str_replace('service_delete_confirm:', '', $context->data);
            if ($serviceId <= 0) {
                error_log(sprintf('[ServiceAction] invalid service_delete_confirm data="%s" actor_id="%s"', $context->data, $context->actorId));
                $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'سرویس معتبر نیست.', true);

                return;
            }

            $this->serviceManagementService->deleteService($serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $serviceId = (int) str_replace('service_delete:', '', $context->data);
        if ($serviceId <= 0) {
            error_log(sprintf('[ServiceAction] invalid service_delete data="%s" actor_id="%s"', $context->data, $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'سرویس معتبر نیست.', true);

            return;
        }

        $this->serviceManagementService->requestDeleteConfirmation($serviceId, $context->chatId, $context->callbackId);
    }
}
