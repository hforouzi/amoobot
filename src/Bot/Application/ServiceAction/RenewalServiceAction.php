<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class RenewalServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_renew:')
            || str_starts_with($callbackData, 'renewal_confirm:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (str_starts_with($context->data, 'service_renew:')) {
            $serviceId = (int) str_replace('service_renew:', '', $context->data);
            $this->serviceManagementService->showRenewalSummary($context->account, $serviceId, $context->chatId, $context->callbackId, $context->isAdmin);

            return;
        }

        $serviceId = (int) str_replace('renewal_confirm:', '', $context->data);
        if ($serviceId <= 0) {
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'عملیات نامعتبر است.', true);

            return;
        }

        $this->serviceManagementService->confirmRenewal($context->account, $serviceId, $context->chatId, $context->callbackId, $context->isAdmin);
    }
}
