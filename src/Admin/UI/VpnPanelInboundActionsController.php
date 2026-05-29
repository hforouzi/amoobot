<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Application\ServiceConfigDeliveryRefresher;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class VpnPanelInboundActionsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
    ) {
    }

    #[Route('/vpn-panel/{id}/test-connection', name: 'admin_vpn_panel_test_connection', methods: ['GET'])]
    public function testPanelConnection(VpnPanel $panel, Sanaei3xuiApiClient $apiClient): RedirectResponse
    {
        if ('sanaei_3xui' !== $panel->getType()) {
            $this->addFlash('danger', 'این عملیات فقط برای پنل sanaei_3xui قابل انجام است.');

            return $this->redirectToPanels();
        }

        $result = $apiClient->login($panel);
        if (($result['ok'] ?? false) !== true) {
            $panel->setLastTestResult('FAIL', 'Auth/login failed');
            $this->entityManager->flush();
            $this->addFlash('danger', $this->safePanelError($result));

            return $this->redirectToPanels();
        }

        $listResult = $apiClient->listInbounds($panel);
        if (($listResult['ok'] ?? false) === true) {
            $payload = is_array($listResult['data'] ?? null) ? $listResult['data'] : [];
            $obj = $payload['obj'] ?? $payload;
            $count = is_array($obj) ? count($obj) : 0;
            $panel->setLastTestResult('OK', sprintf('list_inbounds_ok count=%d', $count));
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('اتصال به پنل موفق بود. تعداد اینباند: %d', $count));
        } else {
            $panel->setLastTestResult('FAIL', sprintf('list_inbounds_failed status=%s error=%s', (string) ($listResult['status'] ?? 'null'), (string) ($listResult['error'] ?? 'unknown')));
            $this->entityManager->flush();
            $this->addFlash('danger', $this->safePanelError($listResult));
        }

        return $this->redirectToPanels();
    }

    #[Route('/vpn-panel/{id}/sync-inbounds', name: 'admin_vpn_panel_sync_inbounds', methods: ['GET'])]
    public function syncPanelInbounds(VpnPanel $panel, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $sync = $inboundSyncService->syncPanelInbounds($panel);
            $this->addFlash('success', sprintf('همگام‌سازی انجام شد: %d اینباند.', $sync->syncedCount));
            if ($sync->missingLocalCount > 0) {
                $this->addFlash('warning', sprintf('%d اینباند محلی در پنل یافت نشد.', $sync->missingLocalCount));
            }
            $this->addFlash('info', 'اینباندها بروزرسانی شدند. اگر External Proxy تغییر کرده، کانفیگ سرویسها را بازسازی کنید.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToPanels();
    }

    #[Route('/vpn-panel/{id}/inbounds', name: 'admin_vpn_panel_view_inbounds', methods: ['GET'])]
    public function viewPanelInbounds(VpnPanel $panel): RedirectResponse
    {
        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => VpnInboundCrudController::class,
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
            $this->addFlash('danger', 'تست ساخت کلاینت فقط برای پنل sanaei_3xui فعال است.');

            return $this->redirectToInbounds();
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

            $this->addFlash('success', sprintf(
                'کلاینت تستی ساخته شد (username: %s). در صورت نیاز از پنل حذف کنید.',
                $created->username ?? $username
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf('ساخت کلاینت تستی ناموفق بود: %s', $e->getMessage()));
        }

        return $this->redirectToInbounds();
    }

    #[Route('/vpn-inbound/{id}/resync', name: 'admin_vpn_inbound_resync', methods: ['GET'])]
    public function resyncInbound(VpnInbound $inbound, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $inboundSyncService->syncInbound($inbound);
            $this->addFlash('success', 'همگام‌سازی مجدد اینباند انجام شد.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToInbounds();
    }

    #[Route('/vpn-inbound/{id}/sync-access-metadata', name: 'admin_vpn_inbound_sync_access_metadata', methods: ['GET'])]
    public function syncInboundAccessMetadata(VpnInbound $inbound, VpnInboundSyncService $inboundSyncService): RedirectResponse
    {
        try {
            $inboundSyncService->syncInbound($inbound);
            $this->addFlash('success', 'همگام‌سازی متادیتای دسترسی انجام شد.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToInbounds();
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
            $this->addFlash('warning', sprintf(
                'کانفیگ %d سرویس بروزرسانی شد. %d سرویس با خطا. (externalProxy count: %d)',
                $updated,
                $failed,
                $externalProxyCount
            ));
        } else {
            $this->addFlash('success', sprintf('کانفیگ %d سرویس بروزرسانی شد.', $updated));
        }

        return $this->redirectToInbounds();
    }

    #[Route('/vpn-service/{id}/regenerate-config', name: 'admin_vpn_service_regenerate_config', methods: ['GET'])]
    public function regenerateServiceConfig(VpnService $service): RedirectResponse
    {
        $uuid = $service->getClientUuid();
        $subId = $service->getSubId();
        $isLegacySanaei = $this->configRefreshService->isSanaeiLegacyService($service);
        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));

        if ((!$isLegacySanaei && (null === $uuid || '' === $uuid || null === $subId || '' === $subId)) || ($isLegacySanaei && (null === $uuid || '' === $uuid) && '' === $email)) {
            $this->addFlash('danger', 'سرویس uuid یا subId ندارد. بازسازی امکان‌پذیر نیست.');

            return $this->redirectToServices();
        }

        try {
            if ($isLegacySanaei) {
                $refresh = $this->deliveryRefresher->refreshBeforeDelivery($service, 'admin_service_regenerate_config');
                if (!$refresh->succeeded) {
                    $this->log(sprintf(
                        'admin_regenerate_config_refresh_failed service_id=%d reason="%s"',
                        $service->getId() ?? 0,
                        (string) ($refresh->reason ?? 'unknown')
                    ));
                    $this->addFlash('danger', sprintf('بازسازی کانفیگ ناموفق: %s', (string) ($refresh->reason ?? 'unknown')));

                    return $this->redirectToServices();
                }

                $this->entityManager->flush();
                $this->log(sprintf('admin_regenerate_config_refresh_success service_id=%d', $service->getId() ?? 0));
                $this->addFlash('success', sprintf('کانفیگ سرویس #%d از پنل بروزرسانی شد. %d لینک.', $service->getId() ?? 0, $refresh->refreshedLinkCount));

                return $this->redirectToServices();
            }

            $rawLinks = $this->normalizedLinks($service->getConfigLinks() ?? []);
            $links = $this->vpnAccessLinkGenerator->generate($service);
            $generatedLinks = $this->normalizedLinks((array) ($links['configLinks'] ?? []));
            $finalLinkSet = $this->finalConfigLinkProvider->deduplicateAndPreferFormattedForService(
                $service,
                $rawLinks,
                $generatedLinks,
                'admin_service_regenerate_config'
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

            $this->addFlash('success', sprintf('کانفیگ سرویس #%d بازسازی شد. %d لینک.', $service->getId() ?? 0, count($configLinks)));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf('بازسازی کانفیگ ناموفق: %s', $e->getMessage()));
        }

        return $this->redirectToServices();
    }


    /**
     * @param array<string, mixed> $result
     */
    private function safePanelError(array $result): string
    {
        $status = $result['status'] ?? null;
        $error = trim((string) ($result['error'] ?? 'unknown_error'));

        if (null === $status) {
            return sprintf('اتصال ناموفق بود. خطا: %s', $error);
        }

        return sprintf('اتصال ناموفق بود. status=%s, error=%s', (string) $status, $error);
    }

    private function redirectToPanels(): RedirectResponse
    {
        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => VpnPanelCrudController::class,
        ]);
    }

    private function redirectToInbounds(): RedirectResponse
    {
        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => VpnInboundCrudController::class,
        ]);
    }

    private function redirectToServices(): RedirectResponse
    {
        return $this->redirectToRoute('admin', [
            'crudAction' => 'index',
            'crudControllerFqcn' => VpnServiceCrudController::class,
        ]);
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
