<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Security\AdminPermission;
use App\Shared\Infrastructure\SafeErrorLog;

final class ResetUsageAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_reset_usage:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->can(AdminPermission::SERVICES_MANAGE)) {
            SafeErrorLog::write(sprintf('[ServiceAction] unauthorized service_reset_usage actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        $serviceId = (int) str_replace('service_reset_usage:', '', $context->data);
        $this->serviceManagementService->resetUsage($serviceId, $context->chatId, $context->callbackId);
        $context->audit('service.reset_usage', $serviceId);
    }
}
