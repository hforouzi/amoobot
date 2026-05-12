<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class AddTrafficOrderAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_add_traffic_order:')
            || str_starts_with($callbackData, 'add_traffic_confirm:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (str_starts_with($context->data, 'service_add_traffic_order:')) {
            $serviceId = (int) str_replace('service_add_traffic_order:', '', $context->data);
            if ($serviceId <= 0) {
                $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'عملیات نامعتبر است.', true);

                return;
            }

            $this->serviceManagementService->startAddTrafficOrder($context->account, $serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $draftId = (int) str_replace('add_traffic_confirm:', '', $context->data);
        if ($draftId <= 0) {
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'عملیات نامعتبر است.', true);

            return;
        }

        $this->serviceManagementService->confirmAddTrafficOrder($context->account, $draftId, $context->chatId, $context->callbackId);
    }
}
