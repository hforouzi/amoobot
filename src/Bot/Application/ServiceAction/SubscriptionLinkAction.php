<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The customer's "subscription link" and "QR" buttons: the service's product module runs them
 * (runCustomerAction(), PM1d). An id that names no service gets the bot's own "invalid service" answer, as before.
 */
final class SubscriptionLinkAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductModuleResolver $productModules,
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
            $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
            if (!$service instanceof VpnService) {
                $this->serviceManagementService->sendSubscriptionQr($context->account, $serviceId, $context->chatId, $context->callbackId);

                return;
            }
            $this->productModules->forService($service)->runCustomerAction($service, CustomerActions::SEND_QR, new CustomerActionInput($context->account, $context->chatId, $context->callbackId));

            return;
        }

        $serviceId = (int) str_replace('service_subscription:', '', $context->data);
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->serviceManagementService->sendSubscription($context->account, $serviceId, $context->chatId, $context->callbackId);

            return;
        }
        $this->productModules->forService($service)->runCustomerAction($service, CustomerActions::SEND_LINK, new CustomerActionInput($context->account, $context->chatId, $context->callbackId));
    }
}
