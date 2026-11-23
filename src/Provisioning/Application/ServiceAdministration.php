<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\ServiceActivationDriverInterface;
use App\Provisioning\Domain\TrafficLimitDriverInterface;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shared\Infrastructure\SafeErrorLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's changes to a service, the same from the bot and the Mini App: suspend, activate, delete, reset usage,
 * extend and add traffic (each on the panel first for a 3x-ui service, nothing changes here if the panel fails; other
 * panel types change here only). The panel call works on the client by its email, which on a multi-inbound service is
 * the one client of every inbound. Callers check the permission, choose the amounts they offer (the bot's presets,
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
            if ($driver instanceof ServiceActivationDriverInterface && VpnCapability::has($driver, VpnCapability::RESUME)) {
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
        // del/{email} removed the client from every inbound, so its additional inbound rows go too: a row of a deleted
        // service would only stop that inbound from being deleted (RESTRICT, docs/design/plan-inbound-add-remove.md R2).
        foreach ($this->entityManager->getRepository(VpnServiceInbound::class)->findBy(['service' => $service]) as $row) {
            $this->entityManager->remove($row);
        }

        return $this->save($service->setStatus(VpnServiceStatus::DELETED));
    }

    public function resetUsage(VpnService $service): ServiceAdminOutcome
    {
        if (!$this->onPanel($service, 'reset_usage', static function ($driver) use ($service): void {
            VpnCapability::require($driver, VpnCapability::TRAFFIC_RESET);
            $driver->resetUsage((string) $service->getRemoteId(), $service->getPanel());
        })) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setTrafficUsedGb(0)->setTrafficUsedBytes(0));
    }

    /**
     * Adds the days to the expiry, or to now when the service has none or is already past it. On a 3x-ui panel the
     * new absolute expiry is set there first (renewService, as a paid renewal): the usage sync copies the panel's
     * expiry back, so a change made here only would be undone within 15 minutes.
     */
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
        $newExpiresAt = $base->modify(sprintf('+%d days', $days));

        $extended = $this->onLivePanel($service, 'extend', function (VpnPanelDriverInterface $driver) use ($service, $days, $newExpiresAt): void {
            // trafficLimitGb null: only the expiry changes, the panel's limit stays.
            $this->renewKeepingSuspension($driver, $service, new RenewVpnServiceRequest(
                durationDays: $days,
                expiresAt: $newExpiresAt,
                serviceId: (int) ($service->getId() ?? 0),
            ));
        });
        if (!$extended) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setExpiresAt($newExpiresAt));
    }

    /**
     * Raises the traffic limit by $trafficGb. On a 3x-ui panel the new absolute limit is set there first, by the
     * driver call a paid add-traffic uses (addTrafficLimit, or renewService for a driver without it).
     */
    public function addTraffic(VpnService $service, int $trafficGb): ServiceAdminOutcome
    {
        if ($trafficGb < 1 || $trafficGb > self::MAX_TRAFFIC_GB) {
            return ServiceAdminOutcome::INVALID_AMOUNT;
        }
        $newLimitGb = ($service->getTrafficLimitGb() ?? 0) + $trafficGb;
        if ($newLimitGb > intdiv(PHP_INT_MAX, self::BYTES_PER_GB)) {
            return ServiceAdminOutcome::LIMIT_OVERFLOW;
        }

        $added = $this->onLivePanel($service, 'add_traffic', function (VpnPanelDriverInterface $driver) use ($service, $newLimitGb): void {
            if ($driver instanceof TrafficLimitDriverInterface && VpnCapability::has($driver, VpnCapability::TRAFFIC_ADD)) {
                $driver->addTrafficLimit((string) $service->getRemoteId(), $newLimitGb, $service->getPanel());

                return;
            }
            $this->renewKeepingSuspension($driver, $service, new RenewVpnServiceRequest(
                durationDays: 0,
                trafficLimitGb: $newLimitGb,
                expiresAt: $service->getExpiresAt(),
                unlimitedDuration: null === $service->getExpiresAt(),
                serviceId: (int) ($service->getId() ?? 0),
            ));
        });
        if (!$added) {
            return ServiceAdminOutcome::PANEL_FAILED;
        }

        return $this->save($service->setTrafficLimitGb($newLimitGb)->setTrafficLimitBytes($newLimitGb * self::BYTES_PER_GB));
    }

    /**
     * onPanel() for a service whose client still exists there: a deleted service's client was removed from the panel
     * (delete()), so its extend / add traffic stays local as it always was.
     *
     * @param callable(VpnPanelDriverInterface): mixed $change
     */
    private function onLivePanel(VpnService $service, string $action, callable $change): bool
    {
        return VpnServiceStatus::DELETED === $service->getStatus() || $this->onPanel($service, $action, $change);
    }

    /**
     * renewService() also enables the client (a renewal lets the customer back in). An admin's extend / add traffic
     * must not undo a suspension, so a suspended service's client is disabled again right after; if that fails the
     * whole change is reported as a panel failure (nothing is saved here) and logged so the admin can suspend again.
     */
    private function renewKeepingSuspension(VpnPanelDriverInterface $driver, VpnService $service, RenewVpnServiceRequest $request): void
    {
        $driver->renewService((string) $service->getRemoteId(), $request, $service->getPanel());
        if (VpnServiceStatus::SUSPENDED !== $service->getStatus()) {
            return;
        }
        try {
            $driver->suspendService((string) $service->getRemoteId(), $service->getPanel());
        } catch (\Throwable $e) {
            SafeErrorLog::write(sprintf('[ServiceAdministration] service_left_enabled_on_panel service_id=%d status=suspended (suspend it again)', (int) $service->getId()));

            throw $e;
        }
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
            SafeErrorLog::write(sprintf('[ServiceAdministration] service_sync_failure action=%s service_id=%d message="%s"', $action, (int) $service->getId(), $e->getMessage()));

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
