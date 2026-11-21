<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Bot\Application\ServiceManagementService;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActions;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Configs" and "resend config": for a customer the service's product module runs it (runCustomerAction(), PM1d). An
 * admin who may assist customers still resends directly (admin mode, any owner); an id that names no service gets the
 * bot's own "invalid service" answer, as before.
 */
final class ResendConfigAction implements ServiceActionInterface
{
    public function __construct(
        private readonly ServiceManagementService $serviceManagementService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductModuleResolver $productModules,
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
            $this->resendAsCustomer($context, $serviceId);

            return;
        }

        $serviceId = (int) str_replace('service_resend_config:', '', $context->data);
        if ($context->can(AdminPermission::SERVICES_ASSIST)) {
            $this->serviceManagementService->resendConfig($context->account, $serviceId, $context->chatId, $context->callbackId, true);

            return;
        }
        $this->resendAsCustomer($context, $serviceId);
    }

    /** ServiceManagementService::resendConfig() without admin mode, through the module when the service exists. */
    private function resendAsCustomer(ServiceActionContext $context, int $serviceId): void
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $this->serviceManagementService->resendConfig($context->account, $serviceId, $context->chatId, $context->callbackId, false);

            return;
        }
        $this->productModules->forService($service)->runCustomerAction($service, CustomerActions::RESEND_CONFIG, new CustomerActionInput($context->account, $context->chatId, $context->callbackId));
    }
}
