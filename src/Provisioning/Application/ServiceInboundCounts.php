<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How many of a service's servers (its primary inbound and the additional ones, docs/design/multi-inbound-plans.md,
 * phase M2b) are connected, and which services or panels have additional inbounds that are not: the customer's "Servers:
 * N", the admin's badge and filter, the panel doctor. Counts only, read from vpn_service_inbound; nothing contacts a
 * panel. Everything is null or empty while plans.multi_inbound_enabled is off, so no page changes then.
 */
final class ServiceInboundCounts
{
    /** Rows that are not attached yet: the scheduled retry (or "Retry attach") is still to attach them. */
    public const NOT_CONNECTED = [VpnServiceInbound::STATUS_FAILED, VpnServiceInbound::STATUS_PENDING];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MultiInboundSettings $settings,
        private readonly VpnPanelDriverRegistry $driverRegistry,
    ) {
    }

    /**
     * The service's servers: the primary inbound plus each additional one it keeps (detached ones are gone), and how
     * many of the additional ones are not connected yet, split for the customer's note (M2c F10): "retrying" ones the
     * scheduled retry will still try (pending, or failed with attempts left, while the retry is on), "stuck" ones it will
     * not (attempts used up, a re-attach loop stopped, the scheduled retry turned off, or, M2d B4, a panel the scheduled
     * run skips because it cannot attach: legacy mode or another type): those need support. Null for a service without
     * additional inbounds (one query).
     *
     * @return array{total: int, notConnected: int, retrying: int, stuck: int}|null
     */
    public function servers(VpnService $service): ?array
    {
        $id = (int) $service->getId();
        if (0 === $id || !$this->settings->enabled()) {
            return null;
        }
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(status = :pending) AS pending, SUM(status = :failed AND attempts < :max) AS failed_left, SUM(status = :failed AND attempts >= :max) AS failed_used_up
             FROM vpn_service_inbound WHERE service_id = :id AND status <> :detached',
            ['pending' => VpnServiceInbound::STATUS_PENDING, 'failed' => VpnServiceInbound::STATUS_FAILED, 'max' => $this->settings->maxAttempts(), 'id' => $id, 'detached' => VpnServiceInbound::STATUS_DETACHED],
        ) ?: [];
        if (0 === (int) ($row['total'] ?? 0)) {
            return null;
        }
        $left = (int) $row['pending'] + (int) $row['failed_left'];
        $retrying = $this->settings->attachRetryEnabled() && $this->panelCanAttach($service) ? $left : 0;
        $stuck = (int) $row['failed_used_up'] + $left - $retrying;

        return ['total' => 1 + (int) $row['total'], 'notConnected' => $retrying + $stuck, 'retrying' => $retrying, 'stuck' => $stuck];
    }

    /**
     * Services (deleted ones aside) with an additional inbound not connected: service id => how many. One query, for a
     * whole admin list page and its tab count. Empty while the switch is off.
     *
     * @return array<int, int>
     */
    public function notConnectedByService(): array
    {
        if (!$this->settings->enabled()) {
            return [];
        }
        $counts = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT r.service_id, COUNT(*) FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE r.status IN (?) AND s.status <> ? GROUP BY r.service_id',
            [self::NOT_CONNECTED, VpnServiceStatus::DELETED],
            [ArrayParameterType::STRING],
        );

        return array_map('intval', $counts);
    }

    /**
     * Additional inbounds of active services that are not attached, per state: all panels, or one. Null while the switch
     * is off.
     *
     * @return array{failed: int, pending: int, givenUp: int}|null
     */
    public function problems(?int $panelId = null): ?array
    {
        if (!$this->settings->enabled()) {
            return null;
        }
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT SUM(r.status = :failed) AS failed, SUM(r.status = :pending) AS pending, SUM(r.status = :failed AND r.attempts >= :max) AS given_up
             FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id
             WHERE s.status = :active AND r.status IN (:statuses)'.(null === $panelId ? '' : ' AND s.panel_id = :panel'),
            ['failed' => VpnServiceInbound::STATUS_FAILED, 'pending' => VpnServiceInbound::STATUS_PENDING, 'max' => $this->settings->maxAttempts(),
                'active' => VpnServiceStatus::ACTIVE, 'statuses' => self::NOT_CONNECTED] + (null === $panelId ? [] : ['panel' => $panelId]),
            ['statuses' => ArrayParameterType::STRING],
        ) ?: [];

        return ['failed' => (int) ($row['failed'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0), 'givenUp' => (int) ($row['given_up'] ?? 0)];
    }

    /** Whether the scheduled run would try this service at all (InboundAttachScheduler skips a panel that cannot attach). */
    private function panelCanAttach(VpnService $service): bool
    {
        $panel = $service->getPanel();

        return null !== $panel && $this->driverRegistry->resolve($panel) instanceof InboundAttachDriverInterface;
    }
}
