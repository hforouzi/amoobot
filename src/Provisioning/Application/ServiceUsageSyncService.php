<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Payment;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\BatchUsageDriverInterface;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderType;
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

    /**
     * @param VpnUsage|null $prefetched usage already read in a batch for this service (syncActiveServices)
     */
    public function syncOne(VpnService $service, bool $dryRun = false, bool $flush = true, ?VpnUsage $prefetched = null): SyncResult
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
            $usage = $prefetched ?? $this->driverRegistry->resolve($panel)->getUsage($remoteId, $panel);
        } catch (\Throwable $e) {
            $safeError = $this->sanitizeMessage($e->getMessage());
            $this->log(sprintf(
                'sync_failed service_id=%d panel_type="%s" email="%s" error="%s"',
                $serviceId,
                (string) $panel->getType(),
                $email,
                $safeError
            ));

            return new SyncResult($serviceId, 'failed', $safeError);
        }

        $usedBytes = $this->resolveBytesValue($usage->usedBytes, $usage->trafficUsedGb);
        $totalBytes = $this->resolveBytesValue($usage->totalBytes, $usage->trafficLimitGb);
        $usedGb = null !== $usedBytes ? $this->bytesToGb($usedBytes) : $usage->trafficUsedGb;
        $limitGb = null !== $totalBytes ? $this->bytesToGb($totalBytes) : $usage->trafficLimitGb;
        $expiresAt = $usage->expiresAt ?? $service->getExpiresAt();
        // A paid renewal / add-traffic whose fulfilment failed is retried from the database values (absolute limit and
        // expiry). If the failed attempt had already reached the panel, taking the panel's values here would make the
        // retry apply the purchase twice; until it is fulfilled only usage is synced.
        $keepPlan = $this->hasUnfulfilledPanelChange($service);
        if ($keepPlan) {
            $totalBytes = $service->getTrafficLimitBytes();
            $limitGb = $service->getTrafficLimitGb();
            $expiresAt = $service->getExpiresAt();
        }

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
            'sync_end service_id=%d panel_type="%s" email="%s" used_bytes=%s total_bytes=%s expiry="%s" is_enabled=%s kept_limit_and_expiry=%s',
            $serviceId,
            (string) $panel->getType(),
            $email,
            null === $usedBytes ? 'null' : (string) $usedBytes,
            null === $totalBytes ? 'null' : (string) $totalBytes,
            $expiresAt?->format('Y-m-d H:i:s') ?? 'null',
            null === $usage->isEnabled ? 'null' : ($usage->isEnabled ? 'true' : 'false'),
            $keepPlan ? 'yes' : 'no'
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
        $prefetched = $this->prefetchBatchUsages($services);

        foreach ($services as $service) {
            $result = $this->syncOne($service, $dryRun, false, $prefetched[spl_object_id($service)] ?? null);
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

    /**
     * Panels whose driver reads many clients in one request (BatchUsageDriverInterface): one request per panel instead
     * of one per service. A panel whose batch fails is left to the per-service path (and its own error reporting).
     *
     * @param VpnService[] $services
     *
     * @return array<int, VpnUsage> by spl_object_id of the service
     */
    private function prefetchBatchUsages(array $services): array
    {
        /** @var array<int, array{panel: VpnPanel, services: list<VpnService>}> $byPanel */
        $byPanel = [];
        foreach ($services as $service) {
            $panel = $service->getPanel();
            if (null === $panel || VpnServiceStatus::DELETED === $service->getStatus() || '' === trim((string) $service->getRemoteId())) {
                continue;
            }
            $byPanel[spl_object_id($panel)]['panel'] = $panel;
            $byPanel[spl_object_id($panel)]['services'][] = $service;
        }

        $usages = [];
        foreach ($byPanel as ['panel' => $panel, 'services' => $panelServices]) {
            try {
                $driver = $this->driverRegistry->resolve($panel);
                if (!$driver instanceof BatchUsageDriverInterface) {
                    continue;
                }
                $batch = $driver->getUsages($panel, array_map(static fn (VpnService $s): string => trim((string) $s->getRemoteId()), $panelServices));
            } catch (\Throwable $e) {
                $this->log(sprintf('batch_usage_failed panel_id=%d error="%s"', $panel->getId() ?? 0, $this->sanitizeMessage($e->getMessage())));
                continue;
            }
            foreach ($panelServices as $service) {
                $usage = $batch[trim((string) $service->getRemoteId())] ?? null;
                if (null !== $usage) {
                    $usages[spl_object_id($service)] = $usage;
                }
            }
        }

        return $usages;
    }

    private function bytesToGb(int $bytes): int
    {
        if ($bytes < 0) {
            $this->log(sprintf('usage_sync_warning reason="negative_bytes_value" value=%d', $bytes));
            return 0;
        }

        if (0 === $bytes) {
            return 0;
        }

        return (int) floor($bytes / self::BYTES_PER_GB);
    }

    private function gbToBytes(int $gb): int
    {
        if ($gb < 0) {
            $this->log(sprintf('usage_sync_warning reason="negative_gb_value" value=%d', $gb));
            return 0;
        }

        if (0 === $gb) {
            return 0;
        }

        return $gb * self::BYTES_PER_GB;
    }

    private function resolveBytesValue(?int $bytes, ?int $gb): ?int
    {
        if (null !== $bytes) {
            return $bytes;
        }

        if (null === $gb) {
            return null;
        }

        return $this->gbToBytes($gb);
    }

    private function log(string $message): void
    {
        error_log('[ServiceUsageSyncService] '.$message);
    }

    private function sanitizeMessage(string $message): string
    {
        $safe = trim($message);
        $safe = preg_replace('/https?:\/\/\S+/i', '[url-redacted]', $safe) ?? $safe;
        $safe = preg_replace('/("?(?:password|passwd|token|cookie|session|authorization|secret|api[_-]?key)"?\s*[:=]\s*)"[^"]*"/i', '$1"[redacted]"', $safe) ?? $safe;
        $safe = preg_replace('/("?(?:password|passwd|token|cookie|session|authorization|secret|api[_-]?key)"?\s*[:=]\s*)([^\s"&]+)/i', '$1[redacted]', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr($safe, 0, 300);
    }

    /** Is a renewal or add-traffic for this service paid but not applied (payment PROVISIONING_FAILED, awaiting a retry)? */
    private function hasUnfulfilledPanelChange(VpnService $service): bool
    {
        if (null === $service->getId()) {
            return false;
        }

        return null !== $this->entityManager->createQueryBuilder()
            ->select('payment.id')
            ->from(Payment::class, 'payment')
            ->join('payment.order', 'o')
            ->where('o.targetService = :service')
            ->andWhere('o.type IN (:types)')
            ->andWhere('payment.status = :status')
            ->setParameter('service', $service)
            ->setParameter('types', [OrderType::RENEWAL, OrderType::ADD_TRAFFIC])
            ->setParameter('status', PaymentStatus::PROVISIONING_FAILED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
