<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;

final class ResendConfigAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_resend_config:')
            || str_starts_with($callbackData, 'service_config_links:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (str_starts_with($context->data, 'service_config_links:')) {
            $serviceId = (int) str_replace('service_config_links:', '', $context->data);
            $this->serviceManagementService->sendConfigLinks($context->account, $serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $serviceId = (int) str_replace('service_resend_config:', '', $context->data);
        $this->serviceManagementService->resendConfig($context->account, $serviceId, $context->chatId, $context->callbackId, $context->isAdmin);
    }
}
