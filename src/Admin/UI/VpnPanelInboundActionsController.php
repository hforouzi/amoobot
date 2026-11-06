<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Support\AdminFormat;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\PanelLinkRefresh;
use App\Provisioning\Application\PanelLinkSnapshot;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\ServiceConfigRegeneration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shared\Infrastructure\SafeErrorLog;
use App\Shop\Application\Catalog\PlanInboundDrift;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin')]
final class VpnPanelInboundActionsController extends AbstractController
{
    use ConfirmPages;
    use TranslatedFlashes;

    /** CSRF token id prefix of the other actions here: the action and the row's id are added. */
    public const CSRF_PREFIX = 'vpn_action_';
    /** CSRF token id prefix of the inbound-wide "regenerate service configs" form (VpnInboundCrudController). */
    public const REGENERATE_INBOUND_CSRF_PREFIX = 'regenerate_inbound_configs_';
    /**
     * At most this many services per click: each modern service is one panel call (up to ~20 s with retries), and they
     * must not all run inside one web request. Larger inbounds use app:inbound:regenerate-service-configs.
     */
    public const WEB_REGENERATE_MAX_SERVICES = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly PanelLinkSnapshot $panelLinkSnapshot,
    ) {
    }

    #[Route('/vpn-panel/{id}/sync-inbounds', name: 'admin_vpn_panel_sync_inbounds', methods: ['GET', 'POST'])]
    public function syncPanelInbounds(VpnPanel $panel, Request $request, VpnInboundSyncService $inboundSyncService): Response
    {
        $csrf = self::CSRF_PREFIX.'sync_panel_'.$panel->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage('action_confirm.sync_inbounds.title', [['action_confirm.sync_inbounds.body', ['%panel%' => $panel->getTitle()]]], $csrf, 'action.sync_inbounds', $this->generateUrl('admin_vpn_panel_index'));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToPanels();
        }

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

    /**
     * A real client on the panel, deleted again in the same request. Only the client this request made is deleted: its
     * name is new (time and random), and a client answered under any other name is left alone.
     */
    #[Route('/vpn-inbound/{id}/test-create-client', name: 'admin_vpn_inbound_test_create_client', methods: ['GET', 'POST'])]
    public function testCreateClient(VpnInbound $inbound, Request $request, VpnPanelDriverRegistry $driverRegistry): Response
    {
        $panel = $inbound->getPanel();
        $csrf = self::CSRF_PREFIX.'test_client_'.$inbound->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage('action_confirm.test_client.title', [['action_confirm.test_client.body', ['%panel%' => $panel->getTitle(), '%inbound%' => $inbound->getTitle()]]], $csrf, 'action.test_create_client', $this->inboundsUrl($inbound));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToInbounds($inbound);
        }
        if ('sanaei_3xui' !== $panel->getType()) {
            $this->flash('danger', 'flash.inbound.test_client_unsupported');

            return $this->redirectToInbounds($inbound);
        }

        $driver = $driverRegistry->resolve($panel);
        $username = sprintf('test_amoobot_%d_%s', time(), bin2hex(random_bytes(3)));

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

        } catch (\Throwable $e) {
            // It may exist anyway: the driver can fail after the panel added it.
            $this->flash('danger', 'flash.inbound.test_client_failed', ['%error%' => $e->getMessage(), '%username%' => $username]);

            return $this->redirectToInbounds($inbound);
        }

        if ($username !== $created->username) {
            $this->flash('warning', 'flash.inbound.test_client_other_name', ['%username%' => $created->username, '%expected%' => $username]);

            return $this->redirectToInbounds($inbound);
        }
        try {
            $driver->deleteService($created->remoteId, $panel);
            $this->flash('success', 'flash.inbound.test_client_removed', ['%username%' => $username]);
        } catch (\Throwable $e) {
            $this->flash('warning', 'flash.inbound.test_client_not_removed', ['%username%' => $username, '%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/resync', name: 'admin_vpn_inbound_resync', methods: ['GET', 'POST'])]
    public function resyncInbound(VpnInbound $inbound, Request $request, VpnInboundSyncService $inboundSyncService): Response
    {
        $csrf = self::CSRF_PREFIX.'resync_'.$inbound->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage('action_confirm.resync_inbound.title', [['action_confirm.resync_inbound.body', ['%inbound%' => $inbound->getTitle()]]], $csrf, 'action.resync_inbound', $this->inboundsUrl($inbound));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToInbounds($inbound);
        }

        try {
            $inboundSyncService->syncInbound($inbound);
            $this->flash('success', 'flash.inbound.resynced');
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.error', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/sync-access-metadata', name: 'admin_vpn_inbound_sync_access_metadata', methods: ['GET', 'POST'])]
    public function syncInboundAccessMetadata(VpnInbound $inbound, Request $request, VpnInboundSyncService $inboundSyncService): Response
    {
        $csrf = self::CSRF_PREFIX.'sync_metadata_'.$inbound->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage('action_confirm.sync_metadata.title', [['action_confirm.sync_metadata.body', ['%inbound%' => $inbound->getTitle()]]], $csrf, 'action.sync_access_metadata', $this->inboundsUrl($inbound));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToInbounds($inbound);
        }

        try {
            $inboundSyncService->syncInbound($inbound);
            $this->flash('success', 'flash.inbound.metadata_synced');
        } catch (\Throwable $e) {
            $this->flash('danger', 'flash.error', ['%error%' => $e->getMessage()]);
        }

        return $this->redirectToInbounds($inbound);
    }

    #[Route('/vpn-inbound/{id}/regenerate-service-configs', name: 'admin_vpn_inbound_regenerate_service_configs', methods: ['GET', 'POST'])]
    public function regenerateInboundServiceConfigs(VpnInbound $inbound, Request $request): Response
    {
        $csrf = self::REGENERATE_INBOUND_CSRF_PREFIX.$inbound->getId();
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')->from(VpnService::class, 's')
            ->where('s.inbound = :inbound')->andWhere('s.status <> :deleted')
            ->setParameter('inbound', $inbound)->setParameter('deleted', VpnServiceStatus::DELETED)
            ->getQuery()->getSingleScalarResult();
        $tooMany = [
            '%count%' => $count,
            '%max%' => self::WEB_REGENERATE_MAX_SERVICES,
            '%command%' => sprintf('php bin/console app:inbound:regenerate-service-configs %d --apply', $inbound->getId() ?? 0),
        ];
        if (!$request->isMethod('POST')) {
            $lines = [['action_confirm.regenerate_inbound.body', ['%count%' => $count, '%inbound%' => $inbound->getTitle()]]];
            if ($count > self::WEB_REGENERATE_MAX_SERVICES) {
                return $this->confirmPage('action_confirm.regenerate_inbound.title', [...$lines, ['flash.inbound.regenerate_use_cli', $tooMany]], $csrf, null, $this->inboundsUrl($inbound));
            }

            return $this->confirmPage('action_confirm.regenerate_inbound.title', $lines, $csrf, 'action.regenerate_service_configs', $this->inboundsUrl($inbound));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToInbounds($inbound);
        }
        if ($count > self::WEB_REGENERATE_MAX_SERVICES) {
            $this->flash('warning', 'flash.inbound.regenerate_use_cli', $tooMany);

            return $this->redirectToInbounds($inbound);
        }

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
            // Modern 3x-ui: the panel's own links (Hosts applied), written per service under the row lock; a failure,
            // or credentials a reset changed meanwhile, keep the stored ones.
            if (null !== $this->panelLinkSnapshot->driverFor($service)) {
                try {
                    $refresh = $this->panelLinkSnapshot->refresh($service, 'admin_inbound_regenerate_configs');
                } catch (\Throwable) {
                    ++$failed;
                    continue;
                }
                match (true) {
                    $refresh->succeeded() => ++$updated,
                    PanelLinkRefresh::NO_IDENTITY === $refresh->outcome => ++$skipped,
                    default => ++$failed,
                };
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
                $this->entityManager->flush();

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

    /** SERVICES_ASSIST (AdminCrudPermissions::ROUTES): the one action here that is not a panel admin's. */
    #[Route('/vpn-service/{id}/regenerate-config', name: 'admin_vpn_service_regenerate_config', methods: ['GET', 'POST'])]
    public function regenerateServiceConfig(VpnService $service, Request $request, ServiceConfigRegenerator $regenerator, PlanInboundDrift $inboundDrift, TranslatorInterface $translator): Response
    {
        $csrf = self::CSRF_PREFIX.'regenerate_service_'.$service->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage('action_confirm.regenerate_service.title', [['action_confirm.regenerate_service.body', ['%id%' => $service->getId(), '%name%' => (string) $service->getUsername()]]], $csrf, 'action.regenerate_config', $this->generateUrl('admin_vpn_service_index'));
        }
        if (!$this->validToken($csrf, $request)) {
            return $this->redirectToServices();
        }

        $result = $regenerator->regenerate($service, 'admin_service_regenerate_config');
        // The service is not on the inbound its plan now sells: the message says why the links are still that inbound's
        // (docs/design/plan-inbound-change.md 3.3 (e)). Otherwise the messages are exactly as before.
        $notice = $result->succeeded() ? $inboundDrift->ofService($service) : null;
        if (null !== $notice) {
            $parameters = ['%id%' => $service->getId() ?? 0, '%count%' => $result->linkCount, '%previous%' => $result->previousLinkCount ?? $result->linkCount];
            $key = ServiceConfigRegeneration::REFRESHED_FROM_PANEL === $result->outcome ? 'flash.service.refreshed_from_panel' : 'flash.service.regenerated';
            // EasyAdmin renders flash messages as HTML: escaped (inbound titles are admin-entered).
            $this->addFlash('success', AdminFormat::e($translator->trans($key, $parameters, 'admin')).' '.AdminFormat::e($inboundDrift->serviceText('plan_inbound_drift.regenerate', $notice)));

            return $this->redirectToServices();
        }
        match ($result->outcome) {
            ServiceConfigRegeneration::NO_IDENTITY => $this->flash('danger', 'flash.service.no_uuid'),
            ServiceConfigRegeneration::REFRESHED_FROM_PANEL => $this->flash('success', 'flash.service.refreshed_from_panel', ['%id%' => $service->getId() ?? 0, '%count%' => $result->linkCount, '%previous%' => $result->previousLinkCount ?? $result->linkCount]),
            ServiceConfigRegeneration::REGENERATED => $this->flash('success', 'flash.service.regenerated', ['%id%' => $service->getId() ?? 0, '%count%' => $result->linkCount]),
            default => $this->flash('danger', 'flash.service.regenerate_failed', ['%error%' => (string) $result->error]),
        };

        return $this->redirectToServices();
    }

    private function validToken(string $csrf, Request $request): bool
    {
        if ($this->isCsrfTokenValid($csrf, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->flash('danger', 'flash.csrf_expired');

        return false;
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

    private function inboundsUrl(VpnInbound $inbound): string
    {
        return $this->generateUrl('admin_vpn_panel_view_inbounds', ['id' => $inbound->getPanel()->getId()]);
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
        SafeErrorLog::write('[VpnPanelInboundActionsController] '.$message);
    }
}
