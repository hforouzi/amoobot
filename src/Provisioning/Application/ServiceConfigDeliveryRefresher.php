<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;

final class ServiceConfigDeliveryRefresher
{
    public function __construct(
        private readonly VpnServiceConfigRefreshService $configRefreshService,
    ) {
    }

    public function refreshBeforeDelivery(VpnService $service, string $sourceFlow): ServiceConfigRefreshOutcome
    {
        $this->log(sprintf(
            'config_delivery_online_refresh_attempt source_flow="%s" service_id=%d',
            $sourceFlow,
            $service->getId() ?? 0
        ));

        if (!$this->configRefreshService->isSanaeiLegacyService($service)) {
            $this->log(sprintf(
                'config_delivery_online_refresh_skipped source_flow="%s" service_id=%d reason="not_sanaei_legacy"',
                $sourceFlow,
                $service->getId() ?? 0
            ));

            return ServiceConfigRefreshOutcome::skipped('not_sanaei_legacy');
        }

        $fallbackToStored = $this->hasStoredAccess($service);
        $result = $this->configRefreshService->refreshSanaeiLegacy($service, $sourceFlow);
        if ($result->succeeded) {
            $this->log(sprintf(
                'config_delivery_online_refresh_success source_flow="%s" service_id=%d refreshed_link_count=%d',
                $sourceFlow,
                $service->getId() ?? 0,
                count($result->configLinks)
            ));

            return ServiceConfigRefreshOutcome::success(count($result->configLinks));
        }

        $this->log(sprintf(
            'config_delivery_online_refresh_failed source_flow="%s" service_id=%d reason="%s" fallback_to_stored=%s',
            $sourceFlow,
            $service->getId() ?? 0,
            $this->sanitize((string) ($result->failureReason ?? 'unknown')),
            $fallbackToStored ? 'yes' : 'no'
        ));

        return ServiceConfigRefreshOutcome::failed((string) ($result->failureReason ?? 'unknown'), $fallbackToStored);
    }

    /**
     * @return list<string>
     */
    private function hasStoredAccess(VpnService $service): bool
    {
        if ('' !== trim((string) ($service->getSubscriptionUrl() ?? ''))) {
            return true;
        }
        if ('' !== trim((string) ($service->getConfigText() ?? ''))) {
            return true;
        }

        return [] !== $this->storedLinks($service);
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

    private function sanitize(string $value): string
    {
        $text = preg_replace('/https?:\/\/\S+/i', '[url-redacted]', $value) ?? $value;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, 240);
    }

    private function log(string $message): void
    {
        error_log('[ServiceConfigDeliveryRefresher] '.$message);
    }
}
