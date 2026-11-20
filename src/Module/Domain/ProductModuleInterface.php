<?php

declare(strict_types=1);

namespace App\Module\Domain;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Application\AutoSuspendSummary;
use App\Provisioning\Application\RenewalResult;
use App\Provisioning\Application\ServiceAdminOutcome;

/**
 * One product type ('vpn', later 'server', ...), the only thing the core will call for a product
 * (docs/design/product-modules.md 2.1, 2.2). Callers outside src/Module/ find it through ProductModuleResolver.
 *
 * PM1b routes settlement through provision()/renew(), PM1c the admin suspend/resume/terminate and the expiry and
 * traffic suspension jobs (runLifecycleTask()). The methods use today's types, the smallest honest choice until the
 * PR that introduces the neutral ones (see "PM1a notes" in the design doc):
 * - the service record is VpnService (2.2: "ServiceRecord stands for today's VpnService entity");
 * - provision() returns the delivered VpnService (DELIVERED only; PENDING and ProvisionContext come with PM5a) and takes
 *   today's metadata array;
 * - renew() returns today's RenewalResult, suspend/resume/terminate today's ServiceAdminOutcome and runLifecycleTask()
 *   today's AutoSuspendSummary, so every caller's outcome stays byte-identical (LifecycleResult and LifecycleReason
 *   wait for a module that needs them).
 * planSchema(), saleBlocker(), lifecyclePolicy(), status(), customerView(), adminView(), customerActions(),
 * runCustomerAction() and scheduledTasks() are left out until the PR that needs them (PM1d, PM3a, PM5a).
 */
interface ProductModuleInterface
{
    /** runLifecycleTask(): suspend the services whose term has ended. */
    public const LIFECYCLE_EXPIRY = 'expiry';
    /** runLifecycleTask(): suspend the services whose traffic is used up. */
    public const LIFECYCLE_TRAFFIC = 'traffic';

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

    /**
     * The module's due lifecycle work for one automation job run (PM1c): find the services the task applies to, at
     * most $limit, and act on them; with $dryRun count only. The core command keeps the job's switch, lock, logging
     * and totals. Tasks: self::LIFECYCLE_EXPIRY (expired services) and self::LIFECYCLE_TRAFFIC (traffic exhausted).
     * Stands in for 2.2's scheduledTasks() until the core job runner (PM5a); see "PM1c" in the design doc's 9.1.
     *
     * @throws \InvalidArgumentException a task this module does not know
     */
    public function runLifecycleTask(string $task, int $limit, bool $dryRun): AutoSuspendSummary;
}
