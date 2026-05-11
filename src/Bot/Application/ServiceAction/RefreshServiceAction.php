<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;

final class RefreshServiceAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_refresh:') || str_starts_with($callbackData, 'service_sync_usage:');
    }

    public function handle(ServiceActionContext $context): void
    {
        $serviceId = 0;
        if (str_starts_with($context->data, 'service_sync_usage:')) {
            $serviceId = (int) str_replace('service_sync_usage:', '', $context->data);
        } elseif (str_starts_with($context->data, 'service_refresh:')) {
            $serviceId = (int) str_replace('service_refresh:', '', $context->data);
        }

        $this->serviceManagementService->syncServiceUsage(
            $context->account,
            $serviceId,
            $context->chatId,
            $context->callbackId,
            $context->isAdmin
        );
    }
}
