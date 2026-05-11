<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;

final class ServiceTrafficAddonService
{
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly VpnPanelDriverRegistry $driverRegistry,
    ) {
    }

    public function addTraffic(VpnService $service, int $trafficGb, Order $order): ServiceTrafficAddonResult
    {
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            throw new \RuntimeException('Deleted service cannot receive traffic add-on.');
        }

        if ($trafficGb <= 0) {
            throw new \RuntimeException('Traffic add-on value is invalid.');
        }

        $currentLimitGb = max(0, (int) ($service->getTrafficLimitGb() ?? 0));
        $newTrafficLimitGb = $currentLimitGb + $trafficGb;
        if ($newTrafficLimitGb <= 0) {
            throw new \RuntimeException('Traffic limit result is invalid.');
        }

        $maxSafeGbForBytes = intdiv(PHP_INT_MAX, self::BYTES_PER_GB);
        if ($newTrafficLimitGb > $maxSafeGbForBytes) {
            throw new \RuntimeException('Traffic limit overflow.');
        }

        $driver = $this->driverRegistry->resolve($service->getPanel());
        if ($driver instanceof Sanaei3xuiDriver) {
            $driver->addTrafficLimit((string) ($service->getRemoteId() ?? ''), $newTrafficLimitGb, $service->getPanel());
        } else {
            $driver->renewService(
                (string) ($service->getRemoteId() ?? ''),
                new RenewVpnServiceRequest(
                    durationDays: 0,
                    trafficLimitGb: $newTrafficLimitGb,
                    expiresAt: $service->getExpiresAt(),
                    unlimitedDuration: null === $service->getExpiresAt(),
                    serviceId: $service->getId() ?? 0,
                    orderId: $order->getId() ?? 0,
                ),
                $service->getPanel()
            );
        }

        $service
            ->setTrafficLimitGb($newTrafficLimitGb)
            ->setTrafficLimitBytes($newTrafficLimitGb * self::BYTES_PER_GB)
            ->setUpdatedAt(new \DateTimeImmutable());

        return new ServiceTrafficAddonResult(
            service: $service,
            addedTrafficGb: $trafficGb,
            newTrafficLimitGb: $newTrafficLimitGb,
        );
    }
}
