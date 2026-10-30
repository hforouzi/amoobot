<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;

final class VpnServiceConfigRefreshService
{
    public function __construct(
        private readonly Sanaei3xuiDriver $sanaei3xuiDriver,
    ) {
    }

    /**
     * What refreshSanaeiLegacy() would store, read from the panel without changing the service (dry runs).
     */
    public function previewSanaeiLegacy(VpnService $service): VpnServiceConfigRefreshResult
    {
        if (!$this->isSanaeiLegacyService($service)) {
            return VpnServiceConfigRefreshResult::skipped();
        }
        try {
            return $this->sanaei3xuiDriver->refreshLegacyServiceConfig($service);
        } catch (\Throwable $e) {
            return VpnServiceConfigRefreshResult::failed($e->getMessage());
        }
    }

    public function refreshSanaeiLegacy(VpnService $service, string $sourceFlow): VpnServiceConfigRefreshResult
    {
        if (!$this->isSanaeiLegacyService($service)) {
            return VpnServiceConfigRefreshResult::skipped();
        }

        try {
            $oldCount = count($this->storedLinks($service));
            $result = $this->sanaei3xuiDriver->refreshLegacyServiceConfig($service);
            if (!$result->succeeded) {
                $this->log(sprintf(
                    'service_config_online_refresh_failed service_id=%d source_flow="%s" reason="%s"',
                    $service->getId() ?? 0,
                    $sourceFlow,
                    ServiceErrorText::redact($service, (string) ($result->failureReason ?? 'unknown'), 240)
                ));

                return $result;
            }

            $configLinks = $result->configLinks;
            $service
                ->setConfigLinks($configLinks)
                ->setConfigText([] !== $configLinks ? implode("\n", $configLinks) : null)
                ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

            if (null !== $result->subscriptionUrl && '' !== trim($result->subscriptionUrl)) {
                $service->setSubscriptionUrl($result->subscriptionUrl);
            }

            $this->log(sprintf(
                'service_config_online_refresh_replaced_old_links service_id=%d source_flow="%s" old_count=%d new_count=%d',
                $service->getId() ?? 0,
                $sourceFlow,
                $oldCount,
                count($configLinks)
            ));

            return $result;
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'service_config_online_refresh_failed service_id=%d source_flow="%s" reason="%s"',
                $service->getId() ?? 0,
                $sourceFlow,
                ServiceErrorText::redact($service, $e->getMessage(), 240)
            ));

            return VpnServiceConfigRefreshResult::failed($e->getMessage());
        }
    }

    public function isSanaeiLegacyService(VpnService $service): bool
    {
        $panel = $service->getPanel();
        if (null === $panel || 'sanaei_3xui' !== strtolower(trim((string) $panel->getType())) || $panel->isModern()) {
            return false;
        }

        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $apiVersion = strtolower(trim((string) ($config['api_version'] ?? $panel->getApiVersion() ?? 'legacy')));

        return 'v3' !== $apiVersion;
    }

    /**
     * @return list<string>
     */
    private function storedLinks(VpnService $service): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $link): string => trim((string) $link), (array) ($service->getConfigLinks() ?? [])),
            static fn (string $link): bool => '' !== $link
        ));
    }

    private function log(string $message): void
    {
        error_log('[VpnServiceConfigRefreshService] '.$message);
    }
}
