<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ServiceAutoSuspendService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function suspendExpiredServices(int $limit = 100, bool $dryRun = false): AutoSuspendSummary
    {
        $now = new \DateTimeImmutable();
        /** @var list<VpnService> $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.status IN (:statuses)')
            ->andWhere('service.expiresAt IS NOT NULL')
            ->andWhere('service.expiresAt < :now')
            ->setParameter('statuses', [VpnServiceStatus::ACTIVE, VpnServiceStatus::EXPIRED])
            ->setParameter('now', $now)
            ->orderBy('service.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        $checked = 0;
        $suspended = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            ++$checked;
            $serviceId = (int) ($service->getId() ?? 0);

            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->suspendService((string) $service->getRemoteId(), $service->getPanel());
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('automation_auto_suspend_expired_failed', [
                    'service_id' => $serviceId,
                    'status' => $service->getStatus(),
                    'panel_type' => $service->getPanel()?->getType(),
                    'message' => $this->sanitize($e->getMessage()),
                ]);
                continue;
            }

            ++$suspended;
            if ($dryRun) {
                continue;
            }

            $service
                ->setStatus(VpnServiceStatus::SUSPENDED)
                ->setUpdatedAt($now)
                ->setLastStatusCheckedAt($now);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new AutoSuspendSummary($checked, $suspended, $failed, $skipped);
    }

    public function suspendTrafficExhaustedServices(int $limit = 100, bool $dryRun = false): AutoSuspendSummary
    {
        /** @var list<VpnService> $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.status = :status')
            ->andWhere('service.trafficLimitGb IS NOT NULL')
            ->andWhere('service.trafficLimitGb > 0')
            ->andWhere('service.trafficUsedGb IS NOT NULL')
            ->andWhere('service.trafficUsedGb >= service.trafficLimitGb')
            ->setParameter('status', VpnServiceStatus::ACTIVE)
            ->orderBy('service.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        $checked = 0;
        $suspended = 0;
        $failed = 0;
        $skipped = 0;
        $now = new \DateTimeImmutable();

        foreach ($services as $service) {
            ++$checked;
            $serviceId = (int) ($service->getId() ?? 0);

            try {
                $driver = $this->driverRegistry->resolve($service->getPanel());
                $driver->suspendService((string) $service->getRemoteId(), $service->getPanel());
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('automation_auto_suspend_traffic_failed', [
                    'service_id' => $serviceId,
                    'status' => $service->getStatus(),
                    'panel_type' => $service->getPanel()?->getType(),
                    'message' => $this->sanitize($e->getMessage()),
                ]);
                continue;
            }

            ++$suspended;
            if ($dryRun) {
                continue;
            }

            $service
                ->setStatus(VpnServiceStatus::SUSPENDED)
                ->setUpdatedAt($now);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new AutoSuspendSummary($checked, $suspended, $failed, $skipped);
    }

    private function sanitize(string $message): string
    {
        $safe = trim($message);
        $safe = preg_replace('/https?:\/\/\S+/i', '[url-redacted]', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr($safe, 0, 300);
    }
}

