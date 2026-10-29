<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\PanelLinkSourceDriverInterface;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Psr\Log\LoggerInterface;

/**
 * The link snapshot of a service whose panel renders its links itself (modern 3x-ui: Hosts, docs/design/
 * multi-inbound-plans.md 9.1, phase H0): vpn_service.configLinks / configText hold exactly what the panel's
 * clients/subLinks gives, nothing the bot built from the inbound, merged or appended.
 *
 * - An answer with links replaces the snapshot; a shorter one too (a host was disabled), with a warning logged and the
 *   removed links in the result, so callers report it.
 * - An empty answer never replaces stored links; a failed call changes nothing. Both are reported to the caller.
 * - $apply=false (dry run) reads the panel and changes nothing. Nothing is flushed here: the caller flushes.
 *
 * Services of other drivers (legacy 3x-ui, other panels) are not handled here: driverFor() returns null.
 */
final class PanelLinkSnapshot
{
    public function __construct(
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly LoggerInterface $logger,
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

    public function refresh(VpnService $service, string $source, bool $apply = true): PanelLinkRefresh
    {
        $before = self::links($service->getConfigLinks() ?? []);
        $driver = $this->driverFor($service);
        $remoteId = trim((string) $service->getRemoteId());
        if (null === $driver || '' === $remoteId) {
            return new PanelLinkRefresh(PanelLinkRefresh::NO_IDENTITY, $before, []);
        }
        $context = ['service_id' => $service->getId(), 'panel_id' => $service->getPanel()?->getId(), 'source' => $source, 'apply' => $apply];

        try {
            $answer = $driver->panelLinks($remoteId, $service->getSubId(), $service->getPanel());
        } catch (\RuntimeException $e) {
            $this->logger->warning('panel_links_snapshot_failed', $context + ['error' => $e->getMessage()]);

            return new PanelLinkRefresh(PanelLinkRefresh::FAILED, $before, [], 0, $e->getMessage());
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
        if ($result->shrank()) {
            $this->logger->warning('panel_links_snapshot_shrank', $context + ['before' => count($before), 'after' => count($after), 'removed' => count($result->removed())]);
        }
        if ($apply) {
            if (PanelLinkRefresh::UPDATED === $outcome) {
                $service->setConfigLinks($after)->setConfigText(implode("\n", $after));
            }
            if (null !== $answer->subscriptionUrl) {
                $service->setSubscriptionUrl($answer->subscriptionUrl);
            }
            $service->setLastAccessInfoSyncedAt(new \DateTimeImmutable());
        }
        $this->logger->info('panel_links_snapshot', $context + ['outcome' => $outcome, 'before' => count($before), 'after' => count($after), 'rejected' => $answer->rejected]);

        return $result;
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
