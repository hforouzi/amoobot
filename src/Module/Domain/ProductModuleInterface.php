<?php

declare(strict_types=1);

namespace App\Module\Domain;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Application\RenewalResult;
use App\Provisioning\Application\ServiceAdminOutcome;

/**
 * One product type ('vpn', later 'server', ...), the only thing the core will call for a product
 * (docs/design/product-modules.md 2.1, 2.2). Found through ProductModuleRegistry by product type.
 *
 * PM1a: nothing calls this yet. The methods use today's types, the smallest honest choice until the PR that introduces
 * the neutral ones (see "PM1a notes" in the design doc):
 * - the service record is VpnService (2.2: "ServiceRecord stands for today's VpnService entity");
 * - provision() returns the delivered VpnService (DELIVERED only; PENDING and ProvisionContext come with PM5a) and takes
 *   today's metadata array;
 * - renew() returns today's RenewalResult, and suspend/resume/terminate today's ServiceAdminOutcome (LifecycleResult
 *   and LifecycleReason come with PM1c).
 * planSchema(), saleBlocker(), lifecyclePolicy(), status(), customerView(), adminView(), customerActions(),
 * runCustomerAction() and scheduledTasks() are left out until the PR that needs them (PM1c, PM1d, PM3a).
 */
interface ProductModuleInterface
{
    /** 'vpn', 'server', ...: unique; later stored in plan.product_type and on the service row (PM2a). */
    public function type(): string;

    /** Contract version this module implements. */
    public function contractVersion(): int;

    /**
     * Fulfil a paid order. Idempotent per order: a second call for the same order adopts what exists.
     *
     * @param array<string, scalar|null> $meta
     */
    public function provision(Order $order, array $meta = []): VpnService;

    public function renew(VpnService $service, Order $order): RenewalResult;

    public function suspend(VpnService $service): ServiceAdminOutcome;

    public function resume(VpnService $service): ServiceAdminOutcome;

    public function terminate(VpnService $service): ServiceAdminOutcome;
}
