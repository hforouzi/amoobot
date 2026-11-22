<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Shop\Domain\OrderType;

/**
 * The product module that owns an order or a service (docs/design/product-modules.md 2.1). Code outside src/Module/
 * asks this, never ProductModuleRegistry (ModuleRegistryUsageGuardTest).
 *
 * PM2a: a service's type is vpn_service.product_type; an order's is its plan's plan.product_type, except that a renewal
 * or add-traffic order belongs to the module of the service it changes (typeOfOrder()). Every existing row is 'vpn'
 * (the columns' default). An unknown type throws the registry's ModuleRegistryException, which is not caught here: each
 * caller fails as it does on any other error of that path, and the jobs skip such a row (UnknownProductTypeGuard).
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


    private function typeOfService(VpnService $service): string
    {
        return $service->getProductType();
    }

    /**
     * A renewal or an add-traffic order changes an existing service, so the service's type wins over the plan's when
     * the two differ (the plan may have been changed since the service was sold). Any other order takes its plan's. An
     * order without a plan (a wallet top-up, which settlement never sends to a module) keeps PM1c's answer, the
     * default type.
     */
    private function typeOfOrder(Order $order): string
    {
        $target = $order->getTargetService();
        if ($target instanceof VpnService && in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $target->getProductType();
        }

        return $order->getPlan()?->getProductType() ?? Plan::DEFAULT_PRODUCT_TYPE;
    }
}
