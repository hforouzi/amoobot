<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\PanelLinkSourceDriverInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shared\Infrastructure\TransactionRollback;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The link snapshot of a service whose panel renders its links itself (modern 3x-ui: Hosts, docs/design/
 * multi-inbound-plans.md 9.1, phase H0): vpn_service.configLinks / configText hold exactly what the panel's
 * clients/subLinks gives, nothing the bot built from the inbound, merged or appended.
 *
 * - An answer with links replaces the snapshot; a shorter one too (a host was disabled), with a warning logged and the
 *   removed links in the result, so callers report it.
 * - An empty answer never replaces stored links; a failed call changes nothing. Both are reported to the caller.
 * - $apply=false (dry run) reads the panel and changes nothing.
 *
 * Writing ($apply=true) is done here, for this one service, and committed: the panel is read with no transaction open
 * and no row lock held; then the vpn_service row is locked by primary key (the only row this locks) and its client
 * UUID, subscription id and remote id are compared with the ones the links were read for. If a credential reset (claim
 * "reset my links") changed them meanwhile, the links read are discarded, nothing is written, and the panel is read once
 * more for the new credentials; a second change is reported as CHANGED_MEANWHILE.
 *
 * Services of other drivers (legacy 3x-ui, other panels) are not handled here: driverFor() returns null.
 */
final class PanelLinkSnapshot
{
    public function __construct(
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRollback $transactionRollback,
    ) {
    }

    public function driverFor(VpnService $service): ?PanelLinkSourceDriverInterface
    {
        $panel = $service->getPanel();
        if (null === $panel) {
            return null;
        }
        try {
            $driver = $this->drivers->resolve($panel);
        } catch (\RuntimeException) {
            return null;
        }

        return $driver instanceof PanelLinkSourceDriverInterface ? $driver : null;
    }

    /**
     * The subscription id the links are read with: the stored one, else the one the remote id carries (a service whose
     * column was never filled). Dry runs and writes use the same one, so both ask the same endpoint.
     */
    public static function effectiveSubId(VpnService $service): ?string
    {
        $subId = trim((string) $service->getSubId());
        if ('' !== $subId) {
            return $subId;
        }
        $ref = (new Sanaei3xuiRemoteIdParser())->parse((string) $service->getRemoteId());
        $fromRemoteId = null === $ref ? '' : trim((string) $ref->subId);

        return '' === $fromRemoteId ? null : $fromRemoteId;
    }

    /**
     * @param bool $recordPanelHealth false for bulk runs: a failing panel call is not counted towards the panel's
     *                                "degraded" state (which stops its sales)
     */
    public function refresh(VpnService $service, string $source, bool $apply = true, bool $recordPanelHealth = true): PanelLinkRefresh
    {
        $result = $this->readAndStore($service, $source, $apply, $recordPanelHealth);
        if (PanelLinkRefresh::CHANGED_MEANWHILE !== $result->outcome) {
            return $result;
        }
        // Once more, for the credentials the service has now.
        $again = $this->readAndStore($service, $source, $apply, $recordPanelHealth);

        return $again->withChangedMeanwhile();
    }

    private function readAndStore(VpnService $service, string $source, bool $apply, bool $recordPanelHealth): PanelLinkRefresh
    {
        $before = self::links($service->getConfigLinks() ?? []);
        $driver = $this->driverFor($service);
        $remoteId = trim((string) $service->getRemoteId());
        if (null === $driver || '' === $remoteId) {
            return new PanelLinkRefresh(PanelLinkRefresh::NO_IDENTITY, $before, []);
        }
        $context = ['service_id' => $service->getId(), 'panel_id' => $service->getPanel()?->getId(), 'source' => $source, 'apply' => $apply];
        // What the links are read for; compared again under the row lock before they are written.
        $identity = self::identity($service);
        $subId = self::effectiveSubId($service);

        try {
            $answer = $driver->panelLinks($remoteId, $subId, $service->getPanel(), $recordPanelHealth);
        } catch (\RuntimeException $e) {
            $error = ServiceErrorText::redact($service, $e->getMessage());
            $this->logger->warning('panel_links_snapshot_failed', $context + ['error' => $error]);

            return new PanelLinkRefresh(PanelLinkRefresh::FAILED, $before, [], 0, $error);
        }
        $after = $answer->links;

        if ([] === $after) {
            $outcome = [] === $before ? PanelLinkRefresh::EMPTY : PanelLinkRefresh::KEPT_EMPTY_ANSWER;
            $this->logger->warning('panel_links_snapshot_empty_answer', $context + ['stored' => count($before), 'rejected' => $answer->rejected]);

            return new PanelLinkRefresh($outcome, $before, [], $answer->rejected, PanelLinkRefresh::EMPTY === $outcome
                ? 'The panel has no links for this client.'
                : 'The panel answered with no links; the stored links were kept.');
        }

        $outcome = $after === $before ? PanelLinkRefresh::UNCHANGED : PanelLinkRefresh::UPDATED;
        $result = new PanelLinkRefresh($outcome, $before, $after, $answer->rejected, null, $apply);
        if ($apply && !$this->storeIfUnchanged($service, $identity, $subId, $after, $answer->subscriptionUrl)) {
            $this->logger->warning('panel_links_snapshot_changed_meanwhile', $context);

            return new PanelLinkRefresh(PanelLinkRefresh::CHANGED_MEANWHILE, $before, [], $answer->rejected,
                'The service\'s credentials changed meanwhile (a link reset?); the links read before were discarded, nothing was written.');
        }
        if ($result->shrank()) {
            $this->logger->warning('panel_links_snapshot_shrank', $context + ['before' => count($before), 'after' => count($after), 'removed' => count($result->removed())]);
        }
        $this->logger->info('panel_links_snapshot', $context + ['outcome' => $outcome, 'before' => count($before), 'after' => count($after), 'rejected' => $answer->rejected]);

        return $result;
    }

    /**
     * Locks the service row (primary key), checks it still has $identity, and writes the links and the sync time in a
     * transaction of its own. Pending changes of the caller are flushed first, outside the lock, so the transaction
     * writes this row only.
     *
     * @param array{string, string, string} $identity
     * @param list<string>                  $links
     *
     * @return bool false when the identity changed meanwhile (nothing written)
     */
    private function storeIfUnchanged(VpnService $service, array $identity, ?string $subId, array $links, ?string $subscriptionUrl): bool
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        if (0 === $level) {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($service, LockMode::PESSIMISTIC_WRITE);
            if (self::identity($service) !== $identity) {
                $connection->rollBack();

                return false;
            }
            if ($links !== self::links($service->getConfigLinks() ?? [])) {
                $service->setConfigLinks($links)->setConfigText(implode("\n", $links));
            }
            if (null !== $subId && '' === trim((string) $service->getSubId())) {
                // The subId was recovered from the remote id: the column gets it now.
                $service->setSubId($subId);
            }
            if (null !== $subscriptionUrl) {
                $service->setSubscriptionUrl($subscriptionUrl);
            }
            $service->setLastAccessInfoSyncedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $connection->commit();

            return true;
        } catch (\Throwable $e) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();
            }

            throw $e;
        }
    }

    /**
     * @return array{string, string, string} client UUID, subscription id, remote id: what a credential reset changes
     */
    private static function identity(VpnService $service): array
    {
        return [(string) $service->getClientUuid(), (string) $service->getSubId(), (string) $service->getRemoteId()];
    }

    /**
     * @param iterable<mixed> $links
     *
     * @return list<string>
     */
    private static function links(iterable $links): array
    {
        $out = [];
        foreach ($links as $link) {
            $link = trim((string) $link);
            if ('' !== $link) {
                $out[] = $link;
            }
        }

        return $out;
    }
}
