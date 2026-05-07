<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Bot\Infrastructure\TelegramApiClient;

final class AddTrafficAction implements ServiceActionInterface
{
    private const ADD_TRAFFIC_CALLBACK_PARTS_COUNT = 3;

    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    public function supports(string $callbackData): bool
    {
        return str_starts_with($callbackData, 'service_add_traffic_menu:') || str_starts_with($callbackData, 'service_add_traffic:');
    }

    public function handle(ServiceActionContext $context): void
    {
        if (!$context->isAdmin) {
            error_log(sprintf('[ServiceAction] unauthorized service_add_traffic actor_id="%s"', $context->actorId));
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'Unauthorized', true);

            return;
        }

        if (str_starts_with($context->data, 'service_add_traffic_menu:')) {
            $serviceId = (int) str_replace('service_add_traffic_menu:', '', $context->data);
            $this->serviceManagementService->showAddTrafficMenu($serviceId, $context->chatId, $context->callbackId);

            return;
        }

        $parts = explode(':', $context->data);
        if (self::ADD_TRAFFIC_CALLBACK_PARTS_COUNT !== count($parts)) {
            $this->telegramApiClient->answerCallbackQuery($context->callbackId, 'عملیات نامعتبر است.', true);

            return;
        }

        $serviceId = (int) ($parts[1] ?? 0);
        $trafficGb = (int) ($parts[2] ?? 0);
        $this->serviceManagementService->addTraffic($serviceId, $trafficGb, $context->chatId, $context->callbackId);
    }
}
