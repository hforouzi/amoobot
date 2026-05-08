<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;

final class SubscriptionLinkAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_subscription:')
            || str_starts_with($callbackData, 'service_subscription_qr:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (str_starts_with($context->data, 'service_subscription_qr:')) {
            $serviceId = (int) str_replace('service_subscription_qr:', '', $context->data);
            $this->serviceManagementService->sendSubscriptionQr($context->account, $serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $serviceId = (int) str_replace('service_subscription:', '', $context->data);
        $this->serviceManagementService->sendSubscription($context->account, $serviceId, $context->chatId, $context->callbackId);
    }
}
