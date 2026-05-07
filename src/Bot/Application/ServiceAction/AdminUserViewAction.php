<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class AdminUserViewAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'admin_user_view:')
            || str_starts_with($callbackData, 'admin_user_services:')
            || str_starts_with($callbackData, 'admin_user_orders:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized admin_user_view actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        if (str_starts_with($context->data, 'admin_user_services:')) {
            $userId = (int) str_replace('admin_user_services:', '', $context->data);
            $this->serviceManagementService->showAdminUserServices($userId, $context->chatId, $context->callbackId);

            return;
        }

        if (str_starts_with($context->data, 'admin_user_orders:')) {
            $userId = (int) str_replace('admin_user_orders:', '', $context->data);
            $this->serviceManagementService->showAdminUserOrders($userId, $context->chatId, $context->callbackId);

            return;
        }

        $userId = (int) str_replace('admin_user_view:', '', $context->data);
        $this->serviceManagementService->showAdminUserDetail($userId, $context->chatId, $context->callbackId);
    }
}
