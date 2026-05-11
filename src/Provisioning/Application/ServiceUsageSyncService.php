<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceUsageSyncService
{
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly ServiceExpiryChecker $serviceExpiryChecker,
    ) {
    }

    public function syncOne(VpnService $service, bool $dryRun = false, bool $flush = true): SyncResult
    {
        $serviceId = (int) ($service->getId() ?? 0);
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return new SyncResult($serviceId, 'skipped', 'deleted');
        }

        $panel = $service->getPanel();
        if (null === $panel) {
            return new SyncResult($serviceId, 'skipped', 'missing_panel');
        }

        if ('dummy' === $panel->getType()) {
            return new SyncResult($serviceId, 'skipped', 'dummy_panel');
        }

        $remoteId = trim((string) $service->getRemoteId());
        if ('' === $remoteId) {
            return new SyncResult($serviceId, 'skipped', 'missing_remote_id');
        }

        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
        $this->log(sprintf(
            'sync_start service_id=%d panel_type="%s" email="%s"',
            $serviceId,
            (string) $panel->getType(),
            $email
        ));

        try {
            $driver = $this->driverRegistry->resolve($panel);
            $usage = $driver->getUsage($remoteId, $panel);
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'sync_failed service_id=%d panel_type="%s" email="%s" error="%s"',
                $serviceId,
                (string) $panel->getType(),
                $email,
                $e->getMessage()
            ));

            return new SyncResult($serviceId, 'failed', $e->getMessage());
        }

        $usedBytes = $usage->usedBytes ?? (null !== $usage->trafficUsedGb ? $this->gbToBytes($usage->trafficUsedGb) : null);
        $totalBytes = $usage->totalBytes ?? (null !== $usage->trafficLimitGb ? $this->gbToBytes($usage->trafficLimitGb) : null);
        $usedGb = null !== $usedBytes ? $this->bytesToGb($usedBytes) : $usage->trafficUsedGb;
        $limitGb = null !== $totalBytes ? $this->bytesToGb($totalBytes) : $usage->trafficLimitGb;
        $expiresAt = $usage->expiresAt ?? $service->getExpiresAt();

        if (!$dryRun) {
            $now = new \DateTimeImmutable();
            $service
                ->setTrafficUsedBytes($usedBytes)
                ->setTrafficLimitBytes($totalBytes)
                ->setTrafficUsedGb($usedGb)
                ->setTrafficLimitGb($limitGb)
                ->setExpiresAt($expiresAt)
                ->setLastUsageSyncedAt($now)
                ->setUpdatedAt($now);
        }

        $expiryResult = $this->serviceExpiryChecker->checkOne($service, $dryRun, false);

        if (!$dryRun && $flush) {
            $this->entityManager->flush();
        }

        $this->log(sprintf(
            'sync_end service_id=%d panel_type="%s" email="%s" used_bytes=%s total_bytes=%s expiry="%s" is_enabled=%s',
            $serviceId,
            (string) $panel->getType(),
            $email,
            null === $usedBytes ? 'null' : (string) $usedBytes,
            null === $totalBytes ? 'null' : (string) $totalBytes,
            $expiresAt?->format('Y-m-d H:i:s') ?? 'null',
            null === $usage->isEnabled ? 'null' : ($usage->isEnabled ? 'true' : 'false')
        ));

        return new SyncResult($serviceId, 'updated', $expiryResult->statusChanged ? 'expiry_status_updated' : null);
    }

    public function syncActiveServices(int $limit = 100, bool $dryRun = false): SyncSummary
    {
        $queryBuilder = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->orderBy('service.id', 'DESC');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var VpnService[] $services */
        $services = $queryBuilder->getQuery()->getResult();

        $checked = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            $result = $this->syncOne($service, $dryRun, false);
            ++$checked;
            if ($result->isUpdated()) {
                ++$updated;
                continue;
            }
            if ($result->isFailed()) {
                ++$failed;
                continue;
            }
            if ($result->isSkipped()) {
                ++$skipped;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new SyncSummary($checked, $updated, $failed, $skipped);
    }

    private function bytesToGb(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        return (int) floor($bytes / self::BYTES_PER_GB);
    }

    private function gbToBytes(int $gb): int
    {
        if ($gb <= 0) {
            return 0;
        }

        return $gb * self::BYTES_PER_GB;
    }

    private function log(string $message): void
    {
        error_log('[ServiceUsageSyncService] '.$message);
    }
}
