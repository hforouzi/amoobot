<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\InboundAttachment;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "Retry attach" of a service's additional inbounds (docs/design/multi-inbound-plans.md 3.2, D2): the inbounds whose
 * vpn_service_inbound row is failed are attached to the service's existing client, the same attach-missing step as at
 * creation (InboundAttachDriverInterface::attachInbounds()): only the ids the client is not on, never a new client.
 *
 * Concurrency (two clicks, two admins): the rows are claimed first in a short transaction (the service row locked by
 * primary key, then its rows; failed -> pending). The panel is called with no lock and no transaction held. The result
 * is written in a second short transaction, only to rows still holding this claim. A second retry meanwhile finds
 * nothing to claim ("in progress") and calls nothing. A claim left by a process that died is taken over after
 * STALE_CLAIM.
 *
 * A future scheduled retry (not built in M2a) calls retry() the same way.
 */
final class ServiceInboundAttacher
{
    public const STALE_CLAIM = '-5 minutes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly MultiInboundSettings $settings,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function retry(VpnService $service, string $source): InboundAttachRetry
    {
        $serviceId = (int) $service->getId();
        if (!$this->settings->enabled()) {
            return new InboundAttachRetry(InboundAttachRetry::SWITCHED_OFF);
        }
        $panel = $service->getPanel();
        if (VpnServiceStatus::DELETED === $service->getStatus() || null === $panel) {
            return new InboundAttachRetry(InboundAttachRetry::NOT_APPLICABLE);
        }
        $driver = $this->driverRegistry->resolve($panel);
        if (!$driver instanceof InboundAttachDriverInterface) {
            return new InboundAttachRetry(InboundAttachRetry::NOT_MODERN, error: InboundAttachDriverInterface::MODERN_PANEL_REQUIRED);
        }

        $claim = new \DateTimeImmutable();
        [$claimed, $busy] = $this->claim($serviceId, $claim);
        if ([] === $claimed) {
            return new InboundAttachRetry($busy ? InboundAttachRetry::IN_PROGRESS : InboundAttachRetry::NOTHING_TO_DO);
        }
        $inbounds = [];
        foreach ($claimed as $rowId => $inboundId) {
            $inbounds[$rowId] = $this->entityManager->find(VpnInbound::class, $inboundId) ?? throw new \LogicException('inbound missing');
        }

        // No lock and no transaction from here to the write: the panel may take seconds.
        try {
            $results = $driver->attachInbounds((string) $service->getRemoteId(), array_values($inbounds), $panel);
        } catch (\Throwable $e) {
            $error = ServiceErrorText::redact($service, $e->getMessage(), 255);
            $this->write($serviceId, $claim, array_map(static fn (): array => [false, $error], $claimed));
            $this->logger->warning('service_inbound_attach_failed', ['service_id' => $serviceId, 'source' => $source, 'inbounds' => count($claimed)]);

            return new InboundAttachRetry(InboundAttachRetry::FAILED, 0, count($claimed), $error);
        }

        $byInbound = [];
        foreach ($results as $result) {
            $byInbound[(int) $result->inbound->getId()] = $result;
        }
        $outcomes = [];
        foreach ($claimed as $rowId => $inboundId) {
            $result = $byInbound[$inboundId] ?? null;
            $outcomes[$rowId] = $result instanceof InboundAttachment && $result->attached
                ? [true, null]
                : [false, ServiceErrorText::redact($service, $result->error ?? 'the panel did not attach it', 255)];
        }
        $this->write($serviceId, $claim, $outcomes);
        $attached = count(array_filter($outcomes, static fn (array $o): bool => $o[0]));
        $this->logger->info('service_inbound_attach_retried', ['service_id' => $serviceId, 'source' => $source, 'attached' => $attached, 'failed' => count($outcomes) - $attached]);

        if ($attached > 0) {
            // The new inbounds' links (design 9.5); its own lock rules, after ours are released.
            $this->panelLinkSnapshot->refresh($service, $source);
        }

        return new InboundAttachRetry(InboundAttachRetry::DONE, $attached, count($outcomes) - $attached);
    }

    /**
     * failed (or pending with a stale claim) -> pending, under the service row lock then the rows' locks.
     *
     * @return array{array<int, int>, bool} claimed row id => inbound id; whether a fresh claim of someone else is open
     */
    private function claim(int $serviceId, \DateTimeImmutable $claim): array
    {
        return $this->transactional(function (Connection $db) use ($serviceId, $claim): array {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id, status, updated_at FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $stale = $claim->modify(self::STALE_CLAIM)->format('Y-m-d H:i:s');
            $claimed = [];
            $busy = false;
            foreach ($rows as $row) {
                $pending = VpnServiceInbound::STATUS_PENDING === $row['status'];
                if (VpnServiceInbound::STATUS_FAILED === $row['status'] || ($pending && (string) $row['updated_at'] < $stale)) {
                    $claimed[(int) $row['id']] = (int) $row['inbound_id'];
                } elseif ($pending) {
                    $busy = true;
                }
            }
            if ([] !== $claimed) {
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, updated_at = ? WHERE id IN (?)',
                    [VpnServiceInbound::STATUS_PENDING, $claim->format('Y-m-d H:i:s'), array_keys($claimed)],
                    [2 => \Doctrine\DBAL\ArrayParameterType::INTEGER],
                );
            }

            return [$claimed, $busy];
        });
    }

    /**
     * Writes the outcome to the rows that still hold this claim (pending, claimed at $claim).
     *
     * @param array<int, array{bool, ?string}> $outcomes row id => [attached, error]
     */
    private function write(int $serviceId, \DateTimeImmutable $claim, array $outcomes): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $claim, $outcomes): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($outcomes as $rowId => [$attached, $error]) {
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, attempts = attempts + 1, last_error = ?, attached_at = CASE WHEN ? = 1 THEN COALESCE(attached_at, ?) ELSE attached_at END, updated_at = ?
                     WHERE id = ? AND service_id = ? AND status = ? AND updated_at = ?',
                    [$attached ? VpnServiceInbound::STATUS_ATTACHED : VpnServiceInbound::STATUS_FAILED, $error, $attached ? 1 : 0, $now, $now, $rowId, $serviceId, VpnServiceInbound::STATUS_PENDING, $claim->format('Y-m-d H:i:s')],
                );
            }
        });
        // Rows this entity manager already holds are now stale (written with SQL above).
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach (array_keys($outcomes) as $rowId) {
            $row = $unitOfWork->tryGetById($rowId, VpnServiceInbound::class);
            if ($row instanceof VpnServiceInbound) {
                $this->entityManager->refresh($row);
            }
        }
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        $db = $this->entityManager->getConnection();
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }
    }
}
