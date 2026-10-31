<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\InboundAttachment;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelCallBudget;
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
 * Three callers:
 * - the sale (afterSale(), M2d B1): right after the settlement's commit, with no order, payment or wallet row lock held,
 *   the rows the sale left pending (AFTER_SALE); one pass of at most AFTER_SALE_BUDGET_SECONDS in all (PanelCallBudget:
 *   every panel call of the pass, the link refresh included, ends by then), what is left stays pending for the
 *   scheduled run. It never changes the order or payment and never throws;
 * - the admin's "Retry attach" (manual): every failed row, whatever its attempts and schedule;
 * - the scheduled retry (InboundAttachScheduler, $scheduled): only the failed rows that are due (next_attempt_at NULL
 *   or past) and have had fewer than MultiInboundSettings::maxAttempts() attempts; the panel's failures are not
 *   counted towards its "degraded" state (a bulk run, as in H0).
 *
 * Either way a failed attempt sets next_attempt_at by the back-off (MultiInboundSettings::nextAttemptAt(): NULL once
 * the attempts are used up), and an attached row clears it.
 */
final class ServiceInboundAttacher
{
    public const STALE_CLAIM = '-5 minutes';
    /** The whole pass after a sale (B1), every panel call included; no lock is held meanwhile. */
    public const AFTER_SALE_BUDGET_SECONDS = 15;
    public const SOURCE_AFTER_SALE = 'after_sale';
    /** last_error of a row claimed by a pass that is attaching it now (replaced by the pass's outcome). */
    public const ATTACHING = 'being attached now';
    private const MODE_MANUAL = 'manual';
    private const MODE_SCHEDULED = 'scheduled';
    private const MODE_AFTER_SALE = 'after_sale';
    public const OTHER_PANEL = 'not attached: the inbound is on another panel than the service';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly MultiInboundSettings $settings,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
        private readonly LoggerInterface $logger,
        private readonly PanelCallBudget $budget,
    ) {
    }

    public function retry(VpnService $service, string $source, bool $scheduled = false): InboundAttachRetry
    {
        return $this->run($service, $source, $scheduled ? self::MODE_SCHEDULED : self::MODE_MANUAL);
    }

    /**
     * Right after a sale's commit (B1): the additional inbounds the sale left pending, in one bounded pass. Must be called
     * outside any transaction. A failure or a slow panel only leaves rows pending or failed for the scheduled run.
     */
    public function afterSale(VpnService $service): InboundAttachRetry
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            // Never under someone's locks: the scheduled run takes the rows once their claim is stale.
            return new InboundAttachRetry(InboundAttachRetry::NOT_APPLICABLE);
        }
        try {
            return $this->budget->within(self::AFTER_SALE_BUDGET_SECONDS, fn (): InboundAttachRetry => $this->run($service, self::SOURCE_AFTER_SALE, self::MODE_AFTER_SALE));
        } catch (\Throwable $e) {
            $this->logger->warning('service_inbound_attach_after_sale_failed', ['service_id' => $service->getId(), 'error' => ServiceErrorText::redact($service, $e->getMessage(), 255)]);

            return new InboundAttachRetry(InboundAttachRetry::FAILED, error: ServiceErrorText::redact($service, $e->getMessage(), 255));
        }
    }

    private function run(VpnService $service, string $source, string $mode): InboundAttachRetry
    {
        $scheduled = self::MODE_MANUAL !== $mode;
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
        [$claimed, $busy] = $this->claim($serviceId, $claim, self::MODE_SCHEDULED === $mode ? $this->settings->maxAttempts() : null, self::MODE_AFTER_SALE === $mode);
        if ([] === $claimed) {
            return new InboundAttachRetry($busy ? InboundAttachRetry::IN_PROGRESS : InboundAttachRetry::NOTHING_TO_DO);
        }
        $inbounds = [];
        $outcomes = [];
        foreach ($claimed as $rowId => [$inboundId, $attempts]) {
            $inbound = $this->entityManager->find(VpnInbound::class, $inboundId) ?? throw new \LogicException('inbound missing');
            if ($inbound->getPanel()->getId() !== $panel->getId()) {
                // Never sent to the panel: one foreign inbound would make the driver refuse the whole call.
                $outcomes[$rowId] = [false, self::OTHER_PANEL, $attempts];
            } else {
                $inbounds[$rowId] = $inbound;
            }
        }
        if ([] === $inbounds) {
            $this->write($serviceId, $claim, $outcomes, self::MODE_AFTER_SALE === $mode);

            return new InboundAttachRetry(InboundAttachRetry::FAILED, 0, count($outcomes), self::OTHER_PANEL);
        }

        // No lock and no transaction from here to the write: the panel may take seconds.
        try {
            $results = $driver->attachInbounds((string) $service->getRemoteId(), array_values($inbounds), $panel, !$scheduled, $this->budget->deadline());
        } catch (\Throwable $e) {
            $error = ServiceErrorText::redact($service, $e->getMessage(), 255);
            foreach (array_keys($inbounds) as $rowId) {
                $outcomes[$rowId] = [false, $error, $claimed[$rowId][1]];
            }
            $this->write($serviceId, $claim, $outcomes, self::MODE_AFTER_SALE === $mode);
            $this->logger->warning('service_inbound_attach_failed', ['service_id' => $serviceId, 'source' => $source, 'inbounds' => count($claimed)]);

            return new InboundAttachRetry(InboundAttachRetry::FAILED, 0, count($claimed), $error);
        }

        $byInbound = [];
        foreach ($results as $result) {
            $byInbound[(int) $result->inbound->getId()] = $result;
        }
        foreach ($inbounds as $rowId => $inbound) {
            $attempts = $claimed[$rowId][1];
            $result = $byInbound[(int) $inbound->getId()] ?? null;
            $outcomes[$rowId] = match (true) {
                $result instanceof InboundAttachment && $result->attached => [true, null, $attempts],
                // Not known (not read back) or not tried in time: stays pending, for the scheduled run once stale.
                $result instanceof InboundAttachment && $result->pending => [false, (string) $result->error, $attempts, InboundAttachDriverInterface::NOT_TRIED !== $result->error],
                default => [false, ServiceErrorText::redact($service, $result->error ?? 'the panel did not attach it', 255), $attempts],
            };
        }
        $this->write($serviceId, $claim, $outcomes, self::MODE_AFTER_SALE === $mode);
        $attached = count(array_filter($outcomes, static fn (array $o): bool => $o[0]));
        $pending = count(array_filter($outcomes, static fn (array $o): bool => isset($o[3])));
        $this->logger->info('service_inbound_attach_retried', ['service_id' => $serviceId, 'source' => $source, 'attached' => $attached, 'pending' => $pending, 'failed' => count($outcomes) - $attached - $pending]);

        if ($attached > 0) {
            // The new inbounds' links (design 9.5); its own lock rules, after ours are released.
            $this->panelLinkSnapshot->refresh($service, $source, true, !$scheduled);
        }

        return new InboundAttachRetry(InboundAttachRetry::DONE, $attached, count($outcomes) - $attached);
    }

    /**
     * failed (or pending with a stale claim) -> pending, under the service row lock then the rows' locks. With
     * $maxAttempts (the scheduled retry) a failed row is claimed only when it is due and has attempts left. With
     * $afterSale only the rows the sale left pending (AFTER_SALE, claimed by nobody yet) are claimed.
     *
     * @return array{array<int, array{int, int}>, bool} claimed row id => [inbound id, attempts so far]; whether a fresh
     *                                                  claim of someone else is open
     */
    private function claim(int $serviceId, \DateTimeImmutable $claim, ?int $maxAttempts, bool $afterSale = false): array
    {
        return $this->transactional(function (Connection $db) use ($serviceId, $claim, $maxAttempts, $afterSale): array {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $rows = $db->fetchAllAssociative('SELECT id, inbound_id, status, attempts, next_attempt_at, updated_at, last_error FROM vpn_service_inbound WHERE service_id = ? ORDER BY id FOR UPDATE', [$serviceId]);
            $now = $claim->format('Y-m-d H:i:s');
            $stale = $claim->modify(self::STALE_CLAIM)->format('Y-m-d H:i:s');
            $claimed = [];
            $busy = false;
            foreach ($rows as $row) {
                $pending = VpnServiceInbound::STATUS_PENDING === $row['status'];
                if ($afterSale) {
                    if ($pending && InboundAttachDriverInterface::AFTER_SALE === $row['last_error']) {
                        $claimed[(int) $row['id']] = [(int) $row['inbound_id'], (int) $row['attempts']];
                    }
                    continue;
                }
                $failed = VpnServiceInbound::STATUS_FAILED === $row['status']
                    && (null === $maxAttempts || ((int) $row['attempts'] < $maxAttempts && (null === $row['next_attempt_at'] || (string) $row['next_attempt_at'] <= $now)));
                if ($failed || ($pending && (string) $row['updated_at'] < $stale)) {
                    $claimed[(int) $row['id']] = [(int) $row['inbound_id'], (int) $row['attempts']];
                } elseif ($pending) {
                    $busy = true;
                }
            }
            if ([] !== $claimed) {
                // The claim also replaces AFTER_SALE, so a second pass after the same sale finds nothing to claim.
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, updated_at = ?, last_error = CASE WHEN last_error = ? THEN ? ELSE last_error END WHERE id IN (?)',
                    [VpnServiceInbound::STATUS_PENDING, $claim->format('Y-m-d H:i:s'), InboundAttachDriverInterface::AFTER_SALE, self::ATTACHING, array_keys($claimed)],
                    [4 => \Doctrine\DBAL\ArrayParameterType::INTEGER],
                );
            }

            return [$claimed, $busy];
        });
    }

    /**
     * Writes the outcome to the rows that still hold this claim (pending, claimed at $claim): attempts + 1, and the next
     * attempt by the back-off (a failure; none for the sale's pass: the next scheduled run) or none (attached). An outcome with a 4th element stays pending (not known, or
     * not tried in time): attempts + 1 only when it was tried, no back-off; the scheduled run takes it once stale.
     *
     * @param array<int, array{0: bool, 1: ?string, 2: int, 3?: bool}> $outcomes row id => [attached, error, attempts before this one, (pending: tried)]
     */
    private function write(int $serviceId, \DateTimeImmutable $claim, array $outcomes, bool $atSale = false): void
    {
        $this->transactional(function (Connection $db) use ($serviceId, $claim, $outcomes, $atSale): void {
            $db->fetchOne('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $at = new \DateTimeImmutable();
            $now = $at->format('Y-m-d H:i:s');
            foreach ($outcomes as $rowId => $outcome) {
                [$attached, $error, $attempts] = $outcome;
                if (isset($outcome[3])) {
                    $db->executeStatement(
                        'UPDATE vpn_service_inbound SET attempts = attempts + ?, last_error = ?, next_attempt_at = NULL, updated_at = ? WHERE id = ? AND service_id = ? AND status = ? AND updated_at = ?',
                        [$outcome[3] ? 1 : 0, VpnServiceInbound::safeError((string) $error), $now, $rowId, $serviceId, VpnServiceInbound::STATUS_PENDING, $claim->format('Y-m-d H:i:s')],
                    );
                    continue;
                }
                // The sale's attempt (now the pass after it) leaves a failed row due at the next scheduled run (M2b).
                $next = $attached || $atSale ? null : $this->settings->nextAttemptAt($attempts + 1, $at)?->format('Y-m-d H:i:s');
                $db->executeStatement(
                    'UPDATE vpn_service_inbound SET status = ?, attempts = attempts + 1, last_error = ?, attached_at = CASE WHEN ? = 1 THEN COALESCE(attached_at, ?) ELSE attached_at END, next_attempt_at = ?, updated_at = ?
                     WHERE id = ? AND service_id = ? AND status = ? AND updated_at = ?',
                    [$attached ? VpnServiceInbound::STATUS_ATTACHED : VpnServiceInbound::STATUS_FAILED, null === $error ? null : VpnServiceInbound::safeError($error), $attached ? 1 : 0, $now, $next, $now, $rowId, $serviceId, VpnServiceInbound::STATUS_PENDING, $claim->format('Y-m-d H:i:s')],
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
