<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\VpnService;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "New subscription page link" on a service's detail page (SERVICES_MANAGE, AdminCrudPermissions): the customer's
 * public page gets a new random token and the old link stops working (e.g. the customer shared it). The panel and
 * the subscription URL itself are not touched. Audited without the tokens.
 */
#[Route('/admin')]
final class SubscriptionPageLinkController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_ID = 'rotate-page-link';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    #[Route('/vpn-service/{id}/rotate-page-link', name: 'admin_vpn_service_rotate_page_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rotate(Request $request, int $id): RedirectResponse
    {
        $service = $this->entityManager->find(VpnService::class, $id);
        if (!$service instanceof VpnService) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_ID.'-'.$id, (string) $request->get('token'))) {
            $this->flash('danger', 'flash.service.page_link_csrf');

            return $this->redirectToRoute('admin_vpn_service_detail', ['entityId' => $id]);
        }

        $service->rotatePublicToken()->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->record('service.rotate_page_link', 'VpnService', $id);
        $this->flash('success', 'flash.service.page_link_rotated', ['%id%' => $id]);

        return $this->redirectToRoute('admin_vpn_service_detail', ['entityId' => $id]);
    }
}
