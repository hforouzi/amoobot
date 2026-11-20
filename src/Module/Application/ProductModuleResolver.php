<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Provisioning\Application\VpnProductModule;

/**
 * The product module that owns an order or a service (docs/design/product-modules.md 2.1). Code outside src/Module/
 * asks this, never ProductModuleRegistry (ModuleRegistryUsageGuardTest).
 *
 * PM1c: every order and service is 'vpn' until PM2a adds plan.product_type and vpn_service.product_type, so typeOf*()
 * is the one place that answers it. An unknown type throws the registry's ModuleRegistryException, which is not caught
 * here: each caller fails as it does on any other error of that path.
 */
final class ProductModuleResolver
{
    public function __construct(private readonly ProductModuleRegistry $modules)
    {
    }

    /**
     * @throws ModuleRegistryException no module of the service's type
     */
    public function forService(VpnService $service): ProductModuleInterface
    {
        return $this->forType($this->typeOfService($service));
    }

    /**
     * @throws ModuleRegistryException no module of the order's type
     */
    public function forOrder(Order $order): ProductModuleInterface
    {
        return $this->forType($this->typeOfOrder($order));
    }

    /**
     * A module by its type, for work that belongs to no single order or service (the lifecycle jobs).
     *
     * @throws ModuleRegistryException no module of that type
     */
    public function forType(string $type): ProductModuleInterface
    {
        return $this->modules->get($type);
    }

    // PM2a: read vpn_service.product_type / plan.product_type here instead of the constant.
    private function typeOfService(VpnService $service): string
    {
        return VpnProductModule::TYPE;
    }

    private function typeOfOrder(Order $order): string
    {
        return VpnProductModule::TYPE;
    }
}
