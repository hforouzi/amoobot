<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\ServiceActivationDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's changes to a service, the same from the bot and the Mini App: suspend, activate, delete, reset usage
 * (each on the panel first for a 3x-ui service, nothing changes here if the panel fails), extend and add traffic
 * (local only, as they always were). Callers check the permission, choose the amounts they offer (the bot's presets,
 * the Mini App's presets or a custom value within MAX_DAYS / MAX_TRAFFIC_GB) and audit.
 */
final class ServiceAdministration
{
    public const MAX_DAYS = 3650;
    public const MAX_TRAFFIC_GB = 100000;
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
    ) {
    }

    public function suspend(VpnService $service): ServiceAdminOutcome
    {
        if (VpnServiceStatus::SUSPENDED === $service->getStatus()) {
            return ServiceAdminOutcome::ALREADY_SUSPENDED;
        }
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return ServiceAdminOutcome::NOT_ALLOWED;
        }
        if (!$this->onPanel($service, 'suspend', static fn ($driver) => $driver->suspendService((string) $service->getRemoteId(), $service->getPanel()))) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setStatus(VpnServiceStatus::SUSPENDED));
    }

    public function activate(VpnService $service): ServiceAdminOutcome
    {
        if (VpnServiceStatus::ACTIVE === $service->getStatus()) {
            return ServiceAdminOutcome::ALREADY_ACTIVE;
        }
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return ServiceAdminOutcome::NOT_ALLOWED;
        }
        $activated = $this->onPanel($service, 'activate', static function ($driver) use ($service): void {
            if ($driver instanceof ServiceActivationDriverInterface) {
                $driver->activateService((string) $service->getRemoteId(), $service->getPanel());
            } else {
                $driver->renewService((string) $service->getRemoteId(), new RenewVpnServiceRequest(
                    durationDays: 0,
                    trafficLimitGb: $service->getTrafficLimitGb(),
                    serviceId: (int) ($service->getId() ?? 0),
                ), $service->getPanel());
            }
        });
        if (!$activated) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setStatus(VpnServiceStatus::ACTIVE));
    }

    public function delete(VpnService $service): ServiceAdminOutcome
    {
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return ServiceAdminOutcome::ALREADY_DELETED;
        }
        if (!$this->onPanel($service, 'delete', static fn ($driver) => $driver->deleteService((string) $service->getRemoteId(), $service->getPanel()))) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setStatus(VpnServiceStatus::DELETED));
    }

    public function resetUsage(VpnService $service): ServiceAdminOutcome
    {
        if (!$this->onPanel($service, 'reset_usage', static fn ($driver) => $driver->resetUsage((string) $service->getRemoteId(), $service->getPanel()))) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setTrafficUsedGb(0)->setTrafficUsedBytes(0));
    }

    /** Adds the days to the expiry, or to now when the service has none or is already past it. */
    public function extend(VpnService $service, int $days): ServiceAdminOutcome
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            return ServiceAdminOutcome::INVALID_AMOUNT;
        }
        $base = $service->getExpiresAt();
        $now = new \DateTimeImmutable();
        if (!$base instanceof \DateTimeImmutable || $base < $now) {
            $base = $now;
        }

        return $this->save($service->setExpiresAt($base->modify(sprintf('+%d days', $days))));
    }

    public function addTraffic(VpnService $service, int $trafficGb): ServiceAdminOutcome
    {
        if ($trafficGb < 1 || $trafficGb > self::MAX_TRAFFIC_GB) {
            return ServiceAdminOutcome::INVALID_AMOUNT;
        }
        $newLimitGb = ($service->getTrafficLimitGb() ?? 0) + $trafficGb;
        if ($newLimitGb > intdiv(PHP_INT_MAX, self::BYTES_PER_GB)) {
            return ServiceAdminOutcome::LIMIT_OVERFLOW;
        }

        return $this->save($service->setTrafficLimitGb($newLimitGb)->setTrafficLimitBytes($newLimitGb * self::BYTES_PER_GB));
    }

    /**
     * Runs the change on the panel first when the service lives on a 3x-ui panel; false if the panel failed.
     *
     * @param callable(\App\Provisioning\Domain\VpnPanelDriverInterface): mixed $change
     */
    private function onPanel(VpnService $service, string $action, callable $change): bool
    {
        $panel = $service->getPanel();
        if (null === $panel || 'sanaei_3xui' !== $panel->getType()) {
            return true;
        }

        try {
            $change($this->driverRegistry->resolve($panel));

            return true;
        } catch (\Throwable $e) {
            error_log(sprintf('[ServiceAdministration] service_sync_failure action=%s service_id=%d message="%s"', $action, (int) $service->getId(), $e->getMessage()));

            return false;
        }
    }

    private function save(VpnService $service): ServiceAdminOutcome
    {
        $service->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return ServiceAdminOutcome::DONE;
    }
}
