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
            [$userId, $backServiceId] = $this->parseUserAndServiceIds($context->data, 'admin_user_services:');
            $this->serviceManagementService->showAdminUserServices($userId, $context->chatId, $context->callbackId, $backServiceId);

            return;
        }

        if (str_starts_with($context->data, 'admin_user_orders:')) {
            [$userId, $backServiceId] = $this->parseUserAndServiceIds($context->data, 'admin_user_orders:');
            $this->serviceManagementService->showAdminUserOrders($userId, $context->chatId, $context->callbackId, $backServiceId);

            return;
        }

        [$userId, $backServiceId] = $this->parseUserAndServiceIds($context->data, 'admin_user_view:');
        $this->serviceManagementService->showAdminUserDetail($userId, $context->chatId, $context->callbackId, $backServiceId);
    }

    /**
     * @return array{int, ?int}
     */
    private function parseUserAndServiceIds(string $data, string $prefix): array
    {
        $raw = str_replace($prefix, '', $data);
        $parts = explode(':', $raw);
        $userId = (int) ($parts[0] ?? 0);
        $backServiceId = isset($parts[1]) ? (int) $parts[1] : null;

        return [$userId, null !== $backServiceId && $backServiceId > 0 ? $backServiceId : null];
    }
}
