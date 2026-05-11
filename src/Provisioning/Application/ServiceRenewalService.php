<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;

final class ServiceRenewalService
{
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
    ) {
    }

    public function renew(VpnService $service, Order $order): RenewalResult
    {
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            throw new \RuntimeException('Deleted service cannot be renewed.');
        }

        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $unlimitedDuration = true === ($metadata['unlimitedDuration'] ?? false);
        $durationDays = (int) ($metadata['durationDays'] ?? 0);
        $renewalTrafficGb = (int) ($metadata['trafficGb'] ?? 0);
        $policy = is_array($metadata['renewalPolicy'] ?? null) ? $metadata['renewalPolicy'] : [];
        $carryRemainingTraffic = (bool) ($policy['carryRemainingTraffic'] ?? $this->renewalSettingsProvider->carryRemainingTraffic());
        $carryRemainingDays = (bool) ($policy['carryRemainingDays'] ?? $this->renewalSettingsProvider->carryRemainingDays());
        $expiredStartFromNow = $this->renewalSettingsProvider->expiredStartFromNow();

        if ($renewalTrafficGb <= 0) {
            throw new \RuntimeException('Renewal traffic value is invalid.');
        }
        if (!$unlimitedDuration && $durationDays <= 0) {
            throw new \RuntimeException('Renewal duration value is invalid.');
        }

        $now = new \DateTimeImmutable();
        $newExpiresAt = null;
        if (!$unlimitedDuration) {
            $baseExpires = $service->getExpiresAt();
            if ($baseExpires instanceof \DateTimeImmutable && $baseExpires > $now) {
                if (!$carryRemainingDays) {
                    $baseExpires = $now;
                }
            } else {
                if ($expiredStartFromNow || !$baseExpires instanceof \DateTimeImmutable) {
                    $baseExpires = $now;
                }
            }
            if (!$baseExpires instanceof \DateTimeImmutable) {
                $baseExpires = $now;
            }
            $newExpiresAt = $baseExpires->modify(sprintf('+%d days', $durationDays));
        }

        $currentLimitGb = max(0, (int) ($service->getTrafficLimitGb() ?? 0));
        $newTrafficLimitGb = $carryRemainingTraffic ? ($currentLimitGb + $renewalTrafficGb) : $renewalTrafficGb;
        if ($newTrafficLimitGb <= 0) {
            throw new \RuntimeException('Renewal traffic limit result is invalid.');
        }

        $driver = $this->driverRegistry->resolve($service->getPanel());
        $driver->renewService(
            (string) ($service->getRemoteId() ?? ''),
            new RenewVpnServiceRequest(
                durationDays: $durationDays,
                trafficLimitGb: $newTrafficLimitGb,
                expiresAt: $newExpiresAt,
                unlimitedDuration: $unlimitedDuration,
            ),
            $service->getPanel()
        );
        if (!$carryRemainingTraffic) {
            $driver->resetUsage((string) ($service->getRemoteId() ?? ''), $service->getPanel());
        }

        $service
            ->setExpiresAt($newExpiresAt)
            ->setTrafficLimitGb($newTrafficLimitGb)
            ->setTrafficLimitBytes($newTrafficLimitGb * self::BYTES_PER_GB)
            ->setTrafficUsedGb($carryRemainingTraffic ? $service->getTrafficUsedGb() : 0)
            ->setTrafficUsedBytes($carryRemainingTraffic ? $service->getTrafficUsedBytes() : 0)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setLastUsageSyncedAt(null)
            ->setUpdatedAt(new \DateTimeImmutable());

        return new RenewalResult(
            service: $service,
            newExpiresAt: $newExpiresAt,
            newTrafficLimitGb: $newTrafficLimitGb,
            addedTrafficGb: $renewalTrafficGb,
            unlimitedDuration: $unlimitedDuration,
            carryRemainingTraffic: $carryRemainingTraffic,
            carryRemainingDays: $carryRemainingDays,
        );
    }
}
