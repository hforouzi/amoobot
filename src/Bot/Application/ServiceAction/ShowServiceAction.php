<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;

final class ShowServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_view:') || str_starts_with($callbackData, 'admin_service_view:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (str_starts_with($context->data, 'service_view:')) {
            $serviceId = (int) str_replace('service_view:', '', $context->data);
            $this->serviceManagementService->showUserServiceDetail($context->account, $serviceId, $context->chatId, $context->callbackId);

            return;
        }

        if (!$context->isAdmin) {
            $this->serviceManagementService->handleMyServices($context->account, $context->chatId, $context->callbackId);

            return;
        }

        $serviceId = (int) str_replace('admin_service_view:', '', $context->data);
        error_log(sprintf('[ServiceAction] admin_service_view_opened service_id=%d actor_id="%s" (includes delete-cancel returns)', $serviceId, $context->actorId));
        $this->serviceManagementService->showAdminServiceDetail($serviceId, $context->chatId, $context->callbackId);
    }
}
