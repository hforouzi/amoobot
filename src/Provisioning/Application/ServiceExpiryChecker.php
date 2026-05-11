<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;

final class ServiceExpiryChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function checkOne(VpnService $service, bool $dryRun = false, bool $flush = true): ExpiryCheckResult
    {
        $serviceId = (int) ($service->getId() ?? 0);
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return new ExpiryCheckResult($serviceId, 'skipped', false, 'deleted');
        }

        $now = new \DateTimeImmutable();
        $statusChanged = false;

        if ($service->getExpiresAt() instanceof \DateTimeImmutable
            && $service->getExpiresAt() < $now
            && VpnServiceStatus::ACTIVE === $service->getStatus()) {
            $statusChanged = true;
        }

        if (!$dryRun) {
            if ($statusChanged) {
                $service->setStatus(VpnServiceStatus::EXPIRED)->setUpdatedAt($now);
            }

            $service->setLastStatusCheckedAt($now);

            if ($flush) {
                $this->entityManager->flush();
            }
        }

        return new ExpiryCheckResult(
            serviceId: $serviceId,
            outcome: $statusChanged ? 'updated' : 'checked',
            statusChanged: $statusChanged,
            message: $statusChanged ? 'expired' : null,
        );
    }

    public function checkAll(bool $dryRun = false, ?int $limit = null): ExpirySummary
    {
        $queryBuilder = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->orderBy('service.id', 'DESC');

        if (null !== $limit && $limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var VpnService[] $services */
        $services = $queryBuilder->getQuery()->getResult();

        $checked = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            ++$checked;
            try {
                $result = $this->checkOne($service, $dryRun, false);
            } catch (\Throwable $e) {
                ++$failed;
                $this->log(sprintf('expiry_check_failed service_id=%d message="%s"', $service->getId() ?? 0, $e->getMessage()));
                continue;
            }
            if ($result->isUpdated()) {
                ++$updated;
                continue;
            }
            if ($result->isSkipped()) {
                ++$skipped;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new ExpirySummary($checked, $updated, $failed, $skipped);
    }

    private function log(string $message): void
    {
        error_log('[ServiceExpiryChecker] '.$message);
    }
}
