<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;

/**
 * The console regeneration of one service's links (app:service:regenerate-config, app:inbound:regenerate-service-
 * configs), as a dry run ($apply=false: the panel is read, nothing is changed) or applied (the caller flushes):
 *
 * - modern 3x-ui: the panel's own links (PanelLinkSnapshot, which writes and commits them itself, under the row lock
 *   and only if the credentials did not change meanwhile); a failure or an empty answer keeps the stored links;
 * - legacy 3x-ui: the legacy driver's refresh, unchanged (VpnServiceConfigRefreshService);
 * - other drivers: links built from the inbound (VpnAccessLinkGenerator), as before.
 */
final class ServiceLinkRegenerator
{
    public function __construct(
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
    ) {
    }

    public function run(VpnService $service, string $source, bool $apply): ServiceLinkChange
    {
        $before = ServiceConfigRegenerator::normalizedLinks($service->getConfigLinks() ?? []);
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return new ServiceLinkChange(ServiceLinkChange::SKIPPED, '-', $before, $before, false, 'deleted');
        }

        if (null !== $this->panelLinkSnapshot->driverFor($service)) {
            $refresh = $this->panelLinkSnapshot->refresh($service, $source, $apply);

            $changed = $refresh->changedMeanwhile;

            return match ($refresh->outcome) {
                PanelLinkRefresh::UPDATED, PanelLinkRefresh::UNCHANGED => new ServiceLinkChange(PanelLinkRefresh::UPDATED === $refresh->outcome ? ServiceLinkChange::UPDATED : ServiceLinkChange::UNCHANGED, 'panel', $refresh->before, $refresh->after, $apply, null, $changed),
                PanelLinkRefresh::KEPT_EMPTY_ANSWER, PanelLinkRefresh::EMPTY => new ServiceLinkChange(ServiceLinkChange::KEPT, 'panel', $refresh->before, $refresh->before, false, $refresh->error, $changed),
                PanelLinkRefresh::NO_IDENTITY => new ServiceLinkChange(ServiceLinkChange::SKIPPED, 'panel', $before, $before, false, 'no client identity'),
                PanelLinkRefresh::CHANGED_MEANWHILE => new ServiceLinkChange(ServiceLinkChange::CHANGED, 'panel', $before, $before, false, $refresh->error, true),
                default => new ServiceLinkChange(ServiceLinkChange::FAILED, 'panel', $refresh->before, $refresh->before, false, $refresh->error, $changed),
            };
        }

        $isLegacy = $this->configRefreshService->isSanaeiLegacyService($service);
        $uuid = trim((string) ($service->getClientUuid() ?? ''));
        $subId = trim((string) ($service->getSubId() ?? ''));
        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
        if ((!$isLegacy && ('' === $uuid || '' === $subId)) || ($isLegacy && '' === $uuid && '' === $email)) {
            return new ServiceLinkChange(ServiceLinkChange::SKIPPED, $isLegacy ? 'legacy' : 'generated', $before, $before, false, 'missing uuid or subId');
        }

        if ($isLegacy) {
            $result = $apply ? $this->configRefreshService->refreshSanaeiLegacy($service, $source) : $this->configRefreshService->previewSanaeiLegacy($service);
            if (!$result->succeeded) {
                return new ServiceLinkChange(ServiceLinkChange::FAILED, 'legacy', $before, $before, false, ServiceErrorText::redact($service, (string) ($result->failureReason ?? 'unknown')));
            }

            return new ServiceLinkChange($result->configLinks === $before ? ServiceLinkChange::UNCHANGED : ServiceLinkChange::UPDATED, 'legacy', $before, $result->configLinks, $apply);
        }

        try {
            $links = $this->vpnAccessLinkGenerator->generate($service);
        } catch (\Throwable $e) {
            return new ServiceLinkChange(ServiceLinkChange::FAILED, 'generated', $before, $before, false, ServiceErrorText::redact($service, $e->getMessage()));
        }
        $after = ServiceConfigRegenerator::normalizedLinks($links['configLinks']);
        if ($apply) {
            $service
                ->setConfigLinks($after)
                ->setConfigText([] !== $after ? implode("\n", $after) : null)
                ->setSubscriptionUrl($links['subscriptionUrl'] ?? $service->getSubscriptionUrl())
                ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());
        }

        return new ServiceLinkChange($after === $before ? ServiceLinkChange::UNCHANGED : ServiceLinkChange::UPDATED, 'generated', $before, $after, $apply);
    }
}
