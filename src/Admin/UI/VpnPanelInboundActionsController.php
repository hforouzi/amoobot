<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class VpnPanelInboundActionsController extends AbstractController
{
    #[Route('/vpn-panel/{id}/test-connection', name: 'admin_vpn_panel_test_connection', methods: ['GET'])]
    public function testPanelConnection(VpnPanel $panel, Sanaei3xuiApiClient $apiClient): RedirectResponse
    {
        if ('sanaei_3xui' !== $panel->getType()) {
            $this->addFlash('danger', 'این عملیات فقط برای پنل sanaei_3xui قابل انجام است.');

            return $this->redirectToPanels();
        }

        $result = $apiClient->login($panel);
        if (($result['ok'] ?? false) === true) {
            $this->addFlash('success', 'اتصال به پنل موفق بود.');
        } else {
            $this->addFlash('danger', $this->safePanelError($result));
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
}
