<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\VpnService;
use App\Provisioning\Application\InboundAttachRetry;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Retry attach" on a service's detail page (SERVICES_MANAGE, AdminCrudPermissions): the service's failed additional
 * inbounds are attached to its existing client (ServiceInboundAttacher: never a second client; two clicks at once send
 * one attach). A POST with a CSRF token, a few per service per 10 minutes (rate limiter admin_service_attach), audited
 * with the counts only.
 */
#[Route('/admin')]
final class ServiceInboundsController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_ID = 'retry-attach';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceInboundAttacher $attacher,
        private readonly AdminAuditLogger $auditLogger,
        #[Autowire(service: 'limiter.admin_service_attach')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/vpn-service/{id}/retry-attach', name: 'admin_vpn_service_retry_attach', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retryAttach(Request $request, int $id): RedirectResponse
    {
        $service = $this->entityManager->find(VpnService::class, $id);
        if (!$service instanceof VpnService) {
            throw $this->createNotFoundException();
        }
        $back = $this->redirectToRoute('admin_vpn_service_detail', ['entityId' => $id]);
        if (!$this->isCsrfTokenValid(self::CSRF_ID.'-'.$id, (string) $request->request->get('token', (string) $request->query->get('token')))) {
            $this->flash('danger', 'flash.service.page_link_csrf');

            return $back;
        }
        if (!$this->limiter->create('service-'.$id)->consume()->isAccepted()) {
            $this->flash('warning', 'flash.service.retry_attach_rate_limited');

            return $back;
        }

        $result = $this->attacher->retry($service, 'admin_retry_attach');
        $this->auditLogger->record('service.retry_attach', 'VpnService', $id, ['outcome' => $result->outcome, 'attached' => $result->attached, 'failed' => $result->failed]);
        match ($result->outcome) {
            InboundAttachRetry::DONE => $this->flash(0 === $result->failed ? 'success' : 'warning', 'flash.service.retry_attach_done', ['%attached%' => $result->attached, '%failed%' => $result->failed]),
            InboundAttachRetry::FAILED => $this->flash('danger', 'flash.service.retry_attach_failed', ['%error%' => (string) $result->error]),
            InboundAttachRetry::NOTHING_TO_DO => $this->flash('info', 'flash.service.retry_attach_nothing_to_do'),
            InboundAttachRetry::IN_PROGRESS => $this->flash('info', 'flash.service.retry_attach_in_progress'),
            InboundAttachRetry::SWITCHED_OFF => $this->flash('info', 'flash.service.retry_attach_switched_off'),
            InboundAttachRetry::NOT_MODERN => $this->flash('info', 'flash.service.retry_attach_not_modern'),
            default => $this->flash('info', 'flash.service.retry_attach_not_applicable'),
        };

        return $back;
    }
}
