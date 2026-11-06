<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\VpnService;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\UI\Admin\MiniAppAdminServiceController;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Security\AdminAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Delete on panel" of a service (SERVICES_DELETE, AdminCrudPermissions): the only web delete, since EasyAdmin's would
 * remove the row and leave the client working on the panel. As in the bot and the Mini App it goes through
 * ServiceAdministration::delete() (the panel first, nothing changes here if that fails), after the service's name is
 * typed back, as the Mini App asks.
 */
#[Route('/admin')]
final class ServiceDeleteController extends AbstractController
{
    use ConfirmPages;
    use TranslatedFlashes;

    public const CSRF_PREFIX = 'vpn_service_delete_';

    #[Route('/vpn-service/{id}/delete-on-panel', name: 'admin_vpn_service_delete_on_panel', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(VpnService $service, Request $request, ServiceAdministration $administration, AdminAuditLogger $auditLogger): Response
    {
        $name = MiniAppAdminServiceController::name($service);
        $csrf = self::CSRF_PREFIX.$service->getId();
        if (!$request->isMethod('POST')) {
            return $this->confirmPage(
                'action_confirm.service_delete.title',
                [['action_confirm.service_delete.body', ['%id%' => $service->getId(), '%name%' => $name, '%panel%' => $service->getPanel()?->getTitle() ?? '-']]],
                $csrf,
                'action.delete_on_panel',
                $this->generateUrl('admin_vpn_service_detail', ['entityId' => $service->getId()]),
                'danger',
                typedName: $name,
            );
        }
        if (!$this->isCsrfTokenValid($csrf, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->confirmAgain($service);
        }
        $typed = trim(AdminText::latinDigits((string) $request->request->get('confirmName')));
        if (0 !== strcasecmp($typed, $name)) {
            $this->flash('danger', 'flash.service.delete_name_mismatch');

            return $this->confirmAgain($service);
        }

        $outcome = $administration->delete($service);
        $auditLogger->record('service.delete', 'VpnService', $service->getId(), ['outcome' => $outcome->value]);
        match ($outcome) {
            ServiceAdminOutcome::DONE => $this->flash('success', 'flash.service.deleted_on_panel', ['%name%' => $name]),
            ServiceAdminOutcome::ALREADY_DELETED => $this->flash('info', 'flash.service.already_deleted', ['%name%' => $name]),
            default => $this->flash('danger', 'flash.service.delete_panel_failed', ['%name%' => $name]),
        };

        return ServiceAdminOutcome::PANEL_FAILED === $outcome
            ? $this->redirectToRoute('admin_vpn_service_detail', ['entityId' => $service->getId()])
            : $this->redirectToRoute('admin_vpn_service_index');
    }

    private function confirmAgain(VpnService $service): RedirectResponse
    {
        return $this->redirectToRoute('admin_vpn_service_delete_on_panel', ['id' => $service->getId()]);
    }
}
