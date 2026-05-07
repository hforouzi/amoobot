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
        return str_starts_with($callbackData, 'service_subscription:');
    }

    public function handle(ServiceActionContext $context): void
    {
        $serviceId = (int) str_replace('service_subscription:', '', $context->data);
        $this->serviceManagementService->sendSubscription($context->account, $serviceId, $context->chatId, $context->callbackId);
    }
}
