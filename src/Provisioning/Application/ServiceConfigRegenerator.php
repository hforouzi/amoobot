<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Regenerate config" of one service (PANELS_MANAGE), from the web panel and the Mini App: a legacy 3x-ui service
 * re-reads its links from the panel (ServiceConfigDeliveryRefresher); any other service rebuilds them from its
 * inbound (VpnAccessLinkGenerator, de-duplicated by FinalConfigLinkProvider) and stores links, text and subscription URL.
 */
final class ServiceConfigRegenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
    ) {
    }

    public function regenerate(VpnService $service, string $source): ServiceConfigRegeneration
    {
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

                return new ServiceConfigRegeneration(ServiceConfigRegeneration::REFRESHED_FROM_PANEL, $refresh->refreshedLinkCount);
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

            return new ServiceConfigRegeneration(ServiceConfigRegeneration::REGENERATED, count($configLinks));
        } catch (\Throwable $e) {
            return new ServiceConfigRegeneration(ServiceConfigRegeneration::FAILED, error: $e->getMessage());
        }
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
        error_log('[ServiceConfigRegenerator] '.$message);
    }
}
