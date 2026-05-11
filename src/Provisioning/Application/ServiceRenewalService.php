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
        $addedTrafficGb = (int) ($metadata['trafficGb'] ?? 0);

        if ($addedTrafficGb <= 0) {
            throw new \RuntimeException('Renewal traffic value is invalid.');
        }
        if (!$unlimitedDuration && $durationDays <= 0) {
            throw new \RuntimeException('Renewal duration value is invalid.');
        }

        $now = new \DateTimeImmutable();
        $newExpiresAt = null;
        if (!$unlimitedDuration) {
            $baseExpires = $service->getExpiresAt();
            if (!$baseExpires instanceof \DateTimeImmutable || $baseExpires < $now) {
                $baseExpires = $now;
            }
            $newExpiresAt = $baseExpires->modify(sprintf('+%d days', $durationDays));
        }

        $currentLimitGb = $service->getTrafficLimitGb() ?? 0;
        $newTrafficLimitGb = $currentLimitGb + $addedTrafficGb;
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

        $service
            ->setExpiresAt($newExpiresAt)
            ->setTrafficLimitGb($newTrafficLimitGb)
            ->setTrafficLimitBytes($newTrafficLimitGb * self::BYTES_PER_GB)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setLastUsageSyncedAt(null)
            ->setUpdatedAt(new \DateTimeImmutable());

        return new RenewalResult(
            service: $service,
            newExpiresAt: $newExpiresAt,
            newTrafficLimitGb: $newTrafficLimitGb,
            addedTrafficGb: $addedTrafficGb,
            unlimitedDuration: $unlimitedDuration,
        );
    }
}
