<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Shared\Infrastructure\SafeErrorLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Regenerate config" of one service (PANELS_MANAGE), from the web panel and the Mini App, and after the owner's
 * "reset my links" (claim links):
 * - a modern 3x-ui service stores the panel's own links (PanelLinkSnapshot: clients/subLinks, Hosts applied), never
 *   links built from the inbound; a failed call or an empty answer keeps the stored links and reports FAILED, and so
 *   do links read for credentials that a reset changed before they could be written;
 * - a legacy 3x-ui service re-reads its links from the panel (ServiceConfigDeliveryRefresher);
 * - any other service rebuilds them from its inbound (VpnAccessLinkGenerator, de-duplicated by
 *   FinalConfigLinkProvider) and stores links, text and subscription URL.
 */
final class ServiceConfigRegenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
    ) {
    }

    public function regenerate(VpnService $service, string $source): ServiceConfigRegeneration
    {
        $previous = count(self::normalizedLinks($service->getConfigLinks() ?? []));
        if (null !== $this->panelLinkSnapshot->driverFor($service)) {
            return $this->fromPanelSnapshot($service, $source, $previous);
        }

        $uuid = $service->getClientUuid();
        $subId = $service->getSubId();
        $isLegacySanaei = $this->configRefreshService->isSanaeiLegacyService($service);
        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));

        if ((!$isLegacySanaei && (null === $uuid || '' === $uuid || null === $subId || '' === $subId)) || ($isLegacySanaei && (null === $uuid || '' === $uuid) && '' === $email)) {
            return new ServiceConfigRegeneration(ServiceConfigRegeneration::NO_IDENTITY);
        }

        try {
            if ($isLegacySanaei) {
                $refresh = $this->deliveryRefresher->refreshBeforeDelivery($service, $source);
                if (!$refresh->succeeded) {
                    $this->log(sprintf('admin_regenerate_config_refresh_failed service_id=%d reason="%s"', $service->getId() ?? 0, (string) ($refresh->reason ?? 'unknown')));

                    return new ServiceConfigRegeneration(ServiceConfigRegeneration::FAILED, error: (string) ($refresh->reason ?? 'unknown'));
                }

                $this->entityManager->flush();
                $this->log(sprintf('admin_regenerate_config_refresh_success service_id=%d', $service->getId() ?? 0));

                return new ServiceConfigRegeneration(ServiceConfigRegeneration::REFRESHED_FROM_PANEL, $refresh->refreshedLinkCount, null, $previous);
            }

            $rawLinks = self::normalizedLinks($service->getConfigLinks() ?? []);
            $links = $this->vpnAccessLinkGenerator->generate($service);
            $generatedLinks = self::normalizedLinks($links['configLinks']);
            $configLinks = $this->finalConfigLinkProvider->deduplicateAndPreferFormattedForService($service, $rawLinks, $generatedLinks, $source)->finalLinks;
            $subscriptionUrl = $links['subscriptionUrl'] ?? null;

            $service
                ->setConfigLinks($configLinks)
                ->setConfigText([] !== $configLinks ? implode("\n", $configLinks) : null)
                ->setSubscriptionUrl($subscriptionUrl ?? $service->getSubscriptionUrl())
                ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return new ServiceConfigRegeneration(ServiceConfigRegeneration::REGENERATED, count($configLinks), null, $previous);
        } catch (\Throwable $e) {
            return new ServiceConfigRegeneration(ServiceConfigRegeneration::FAILED, error: ServiceErrorText::redact($service, $e->getMessage()));
        }
    }

    private function fromPanelSnapshot(VpnService $service, string $source, int $previous): ServiceConfigRegeneration
    {
        try {
            $refresh = $this->panelLinkSnapshot->refresh($service, $source);
        } catch (\Throwable $e) {
            return new ServiceConfigRegeneration(ServiceConfigRegeneration::FAILED, $previous, ServiceErrorText::redact($service, $e->getMessage()), $previous);
        }
        if (PanelLinkRefresh::NO_IDENTITY === $refresh->outcome) {
            return new ServiceConfigRegeneration(ServiceConfigRegeneration::NO_IDENTITY, $previous, null, $previous);
        }
        if (!$refresh->succeeded()) {
            $this->log(sprintf('admin_regenerate_config_panel_links_kept service_id=%d outcome="%s"', $service->getId() ?? 0, $refresh->outcome));

            return new ServiceConfigRegeneration(ServiceConfigRegeneration::FAILED, $previous, (string) $refresh->error, $previous);
        }
        // Written and committed by PanelLinkSnapshot (row locked, credentials re-checked): nothing to flush here.

        return new ServiceConfigRegeneration(ServiceConfigRegeneration::REFRESHED_FROM_PANEL, $refresh->snapshotCount(), null, $previous);
    }

    /**
     * @param iterable<mixed> $links
     *
     * @return list<string>
     */
    public static function normalizedLinks(iterable $links): array
    {
        $normalized = [];
        foreach ($links as $link) {
            $candidate = trim((string) $link);
            if ('' !== $candidate) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private function log(string $message): void
    {
        SafeErrorLog::write('[ServiceConfigRegenerator] '.$message);
    }
}
