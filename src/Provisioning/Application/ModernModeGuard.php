<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Plan;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether a panel may be switched to the modern API. Forcing "modern" on a panel that sells (used by an active plan)
 * is refused until a connection test confirmed the modern API (last probe "ok", detected modern) and the modern
 * driver can provision; "auto" never needs this: it only becomes modern after such a probe.
 */
final class ModernModeGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
    ) {
    }

    /**
     * @return array{key: string, params: array<string, string>}|null a translation key (domain "admin") explaining
     *                                                                 the refusal, or null when allowed
     */
    public function refusal(VpnPanel $panel, SanaeiIntegrationMode $requested): ?array
    {
        if (SanaeiIntegrationMode::MODERN !== $requested || 'sanaei_3xui' !== $panel->getType()) {
            return null;
        }
        if (!$panel->isApiTokenConfigured()) {
            return ['key' => 'panel_connection.guard.no_token', 'params' => []];
        }
        if (!$this->usedByActivePlan($panel)) {
            return null;
        }
        $confirmed = SanaeiIntegrationMode::MODERN === $panel->getDetectedIntegrationMode() && 'ok' === $panel->getProbeStatus();
        if (!$confirmed) {
            return ['key' => 'panel_connection.guard.not_confirmed', 'params' => ['%status%' => $panel->getProbeStatus() ?? '-']];
        }
        $probe = (clone $panel)->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        if (!$this->driverRegistry->resolve($probe)->supportsProvisioning($probe)) {
            return ['key' => 'panel_connection.guard.driver_incomplete', 'params' => []];
        }

        return null;
    }

    public function usedByActivePlan(VpnPanel $panel): bool
    {
        if (null === $panel->getId()) {
            return false;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(plan.id)')
            ->from(Plan::class, 'plan')
            ->join('plan.inbound', 'inbound')
            ->where('inbound.panel = :panel')
            ->andWhere('plan.isActive = true')
            ->setParameter('panel', $panel)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
