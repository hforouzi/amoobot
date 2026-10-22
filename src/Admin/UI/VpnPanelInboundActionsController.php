<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\ServiceConfigRegeneration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class VpnPanelInboundActionsController extends AbstractController
{
    use TranslatedFlashes;


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
    ) {
    }

    #[Route('/vpn-panel/{id}/sync-inbounds', name: 'admin_vpn_panel_sync_inbounds', methods: ['GET'])]
    public function syncPanelInbounds(VpnPanel $panel, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $sync = $inboundSyncService->syncPanelInbounds($panel);
            $this->flash('success', 'flash.inbound.synced', ['%count%' => $sync->syncedCount]);
            if ($sync->missingLocalCount > 0) {
                $this->flash('warning', 'flash.inbound.missing_locally', ['%count%' => $sync->missingLocalCount]);
            }
            $this->flash('info', 'flash.inbound.updated_hint');
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.error', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToPanels();
    }

    #[Route('/vpn-panel/{id}/inbounds', name: 'admin_vpn_panel_view_inbounds', methods: ['GET'])]
    public function viewPanelInbounds(VpnPanel $panel): RedirectResponse
    {
        // The pretty route: the old "?crudAction=..." query format only shows the dashboard with pretty URLs.
        return $this->redirectToRoute('admin_vpn_inbound_index', [
            'filters' => [
                'panel' => [
                    'comparison' => '=',
                    'value' => (string) ($panel->getId() ?? 0),
                ],
            ],
        ]);
    }

    #[Route('/vpn-inbound/{id}/test-create-client', name: 'admin_vpn_inbound_test_create_client', methods: ['GET'])]
    public function testCreateClient(VpnInbound $inbound, VpnPanelDriverRegistry $driverRegistry): RedirectResponse
    {
        $panel = $inbound->getPanel();
        if ('sanaei_3xui' !== $panel->getType()) {
            $this->flash('danger', 'flash.inbound.test_client_unsupported');

            return $this->redirectToInbounds($inbound);
        }

        $driver = $driverRegistry->resolve($panel);
        $username = sprintf('test_amoobot_%d', time());

        try {
            $created = $driver->createService(new CreateVpnServiceRequest(
                username: $username,
                durationDays: 1,
                trafficLimitGb: 1,
                ipLimit: 1,
                inbound: $inbound,
                remoteInboundId: $inbound->getRemoteInboundId(),
                meta: ['test' => true],
            ), $panel);

            $this->flash('success', 'flash.inbound.test_client_created', ['%username%' => $created->username ?? $username]);
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.inbound.test_client_failed', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/resync', name: 'admin_vpn_inbound_resync', methods: ['GET'])]
    public function resyncInbound(VpnInbound $inbound, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $inboundSyncService->syncInbound($inbound);
            $this->flash('success', 'flash.inbound.resynced');
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.error', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/sync-access-metadata', name: 'admin_vpn_inbound_sync_access_metadata', methods: ['GET'])]
    public function syncInboundAccessMetadata(VpnInbound $inbound, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $inboundSyncService->syncInbound($inbound);
            $this->flash('success', 'flash.inbound.metadata_synced');
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.error', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/regenerate-service-configs', name: 'admin_vpn_inbound_regenerate_service_configs', methods: ['GET'])]
    public function regenerateInboundServiceConfigs(VpnInbound $inbound): RedirectResponse
    {
        $config = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $externalProxyList = $config['externalProxyList'] ?? [];
        $externalProxyCount = is_array($externalProxyList) ? count($externalProxyList) : 0;

        /** @var VpnService[] $services */
        $services = $this->entityManager->getRepository(VpnService::class)->findBy(['inbound' => $inbound]);

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($services as $service) {
            if (VpnServiceStatus::DELETED === $service->getStatus()) {
                ++$skipped;
                continue;
            }

            $isLegacySanaei = $this->configRefreshService->isSanaeiLegacyService($service);
            $uuid = trim((string) ($service->getClientUuid() ?? ''));
            $subId = trim((string) ($service->getSubId() ?? ''));
            $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
            if ((!$isLegacySanaei && ('' === $uuid || '' === $subId)) || ($isLegacySanaei && '' === $uuid && '' === $email)) {
                ++$skipped;
                continue;
            }

            try {
                if ($isLegacySanaei) {
                    $refresh = $this->deliveryRefresher->refreshBeforeDelivery($service, 'admin_inbound_regenerate_configs');
                    if ($refresh->succeeded) {
                        $this->log(sprintf('admin_regenerate_config_refresh_success service_id=%d', $service->getId() ?? 0));
                        ++$updated;
                    } else {
                        $this->log(sprintf(
                            'admin_regenerate_config_refresh_failed service_id=%d reason="%s"',
                            $service->getId() ?? 0,
                            (string) ($refresh->reason ?? 'unknown')
                        ));
                        ++$failed;
                    }

                    continue;
                }

                $rawLinks = $this->normalizedLinks($service->getConfigLinks() ?? []);
                $links = $this->vpnAccessLinkGenerator->generate($service);
                $generatedLinks = $this->normalizedLinks((array) ($links['configLinks'] ?? []));
                $finalLinkSet = $this->finalConfigLinkProvider->deduplicateAndPreferFormattedForService(
                    $service,
                    $rawLinks,
                    $generatedLinks,
                    'admin_inbound_regenerate_configs'
                );
                $configLinks = $finalLinkSet->finalLinks;
                $subscriptionUrl = $links['subscriptionUrl'] ?? null;
                $finalConfigText = [] !== $configLinks ? implode("\n", $configLinks) : null;

                $service
                    ->setConfigLinks($configLinks)
                    ->setConfigText($finalConfigText)
                    ->setSubscriptionUrl($subscriptionUrl ?? $service->getSubscriptionUrl())
                    ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

                ++$updated;
            } catch (\Throwable) {
                ++$failed;
            }
        }

        $this->entityManager->flush();

        if ($failed > 0) {
            $this->flash('warning', 'flash.inbound.configs_partially_updated', ['%updated%' => $updated, '%failed%' => $failed, '%proxies%' => $externalProxyCount]);
        } else {
            $this->flash('success', 'flash.inbound.configs_updated', ['%count%' => $updated]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-service/{id}/regenerate-config', name: 'admin_vpn_service_regenerate_config', methods: ['GET'])]
    public function regenerateServiceConfig(VpnService $service, ServiceConfigRegenerator $regenerator): RedirectResponse
    {
        $result = $regenerator->regenerate($service, 'admin_service_regenerate_config');
        match ($result->outcome) {
            ServiceConfigRegeneration::NO_IDENTITY => $this->flash('danger', 'flash.service.no_uuid'),
            ServiceConfigRegeneration::REFRESHED_FROM_PANEL => $this->flash('success', 'flash.service.refreshed_from_panel', ['%id%' => $service->getId() ?? 0, '%count%' => $result->linkCount]),
            ServiceConfigRegeneration::REGENERATED => $this->flash('success', 'flash.service.regenerated', ['%id%' => $service->getId() ?? 0, '%count%' => $result->linkCount]),
            default => $this->flash('danger', 'flash.service.regenerate_failed', ['%error%' => (string) $result->error]),
        };

        return $this->redirectToServices();
    }


    private function redirectToPanels(): RedirectResponse
    {
        return $this->redirectToRoute('admin_vpn_panel_index');
    }

    /** Back to the inbound's panel list (inbounds are reached from their panel). */
    private function redirectToInbounds(VpnInbound $inbound): RedirectResponse
    {
        return $this->redirectToRoute('admin_vpn_panel_view_inbounds', ['id' => $inbound->getPanel()->getId()]);
    }

    private function redirectToServices(): RedirectResponse
    {
        return $this->redirectToRoute('admin_vpn_service_index');
    }

    /**
     * @param iterable<mixed> $links
     *
     * @return list<string>
     */
    private function normalizedLinks(iterable $links): array
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
        error_log('[VpnPanelInboundActionsController] '.$message);
    }
}
