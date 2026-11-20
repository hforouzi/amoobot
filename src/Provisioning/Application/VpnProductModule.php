<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Domain\ProductModuleInterface;

/**
 * The built-in 'vpn' product module (docs/design/product-modules.md 2.4): every method delegates to the existing
 * service, unchanged. PM1b routes settlement through it, PM1c the admin lifecycle actions and the suspension jobs.
 *
 * - provision: VpnProvisioningService::provisionOrder() with adoption on, as settlement does today, so a second call
 *   for the same order adopts the client it created (orderClientUuid(), findAdoptableClient()).
 * - renew: ServiceRenewalService::renew().
 * - suspend / resume / terminate: the admin path of ServiceAdministration (suspend(), activate(), delete()), as it
 *   stands, including its rule that suspend only calls the panel for sanaei_3xui.
 * - runLifecycleTask: the job path, ServiceAutoSuspendService::suspendExpiredServices() ('expiry') and
 *   suspendTrafficExhaustedServices() ('traffic'), as they stand: the batch query and the per-service driver calls
 *   stay there, and that path always resolves a driver. The two suspend paths are not unified (design 2.4).
 */
final class VpnProductModule implements ProductModuleInterface
{
    public const TYPE = 'vpn';
    public const VERSION = 1;

    public function __construct(
        private readonly VpnProvisioningService $provisioning,
        private readonly ServiceRenewalService $renewal,
        private readonly ServiceAdministration $administration,
        private readonly ServiceAutoSuspendService $autoSuspend,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function contractVersion(): int
    {
        return self::VERSION;
    }

    public function provision(Order $order, array $meta = []): VpnService
    {
        return $this->provisioning->provisionOrder($order, $meta, adoptExisting: true);
    }

    public function renew(VpnService $service, Order $order): RenewalResult
    {
        return $this->renewal->renew($service, $order);
    }

    public function suspend(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->suspend($service);
    }

    public function resume(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->activate($service);
    }

    public function terminate(VpnService $service): ServiceAdminOutcome
    {
        return $this->administration->delete($service);
    }

    public function runLifecycleTask(string $task, int $limit, bool $dryRun): AutoSuspendSummary
    {
        return match ($task) {
            self::LIFECYCLE_EXPIRY => $this->autoSuspend->suspendExpiredServices($limit, $dryRun),
            self::LIFECYCLE_TRAFFIC => $this->autoSuspend->suspendTrafficExhaustedServices($limit, $dryRun),
            default => throw new \InvalidArgumentException(sprintf('The "%s" module has no lifecycle task "%s".', self::TYPE, $task)),
        };
    }
}
