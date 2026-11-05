<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\VpnService;
use App\Provisioning\Application\InboundAttachRetry;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveOverview;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Shop\Application\InboundMoveSettings;
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
 *
 * "Retry now" of a service's failed inbound move (docs/design/plan-inbound-change.md 5.6, PR P7): its move is due again
 * at once, attempts from zero (InboundMoveControls::retryFailed(), as the run's "Retry failed now"); the next pass of the
 * scheduled task tries it. No panel call here. A POST with a CSRF token, rate limited per service (admin_service_move).
 * No audit row per service (5.6: the move row is the record).
 */
#[Route('/admin')]
final class ServiceInboundsController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_ID = 'retry-attach';
    public const CSRF_MOVE = 'retry-move';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceInboundAttacher $attacher,
        private readonly AdminAuditLogger $auditLogger,
        #[Autowire(service: 'limiter.admin_service_attach')]
        private readonly RateLimiterFactory $limiter,
        private readonly InboundMoveControls $moveControls,
        private readonly InboundMoveOverview $moveOverview,
        private readonly InboundMoveSettings $moveSettings,
        #[Autowire(service: 'limiter.admin_service_move')]
        private readonly RateLimiterFactory $moveLimiter,
    ) {
    }

    #[Route('/vpn-service/{id}/retry-move', name: 'admin_vpn_service_retry_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retryMove(Request $request, int $id): RedirectResponse
    {
        if (null === $this->entityManager->find(VpnService::class, $id)) {
            throw $this->createNotFoundException();
        }
        $back = $this->redirectToRoute('admin_vpn_service_detail', ['entityId' => $id]);
        if (!$this->isCsrfTokenValid(self::CSRF_MOVE.'-'.$id, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $back;
        }
        if (!$this->moveSettings->enabled()) {
            $this->flash('danger', 'inbound_move.flash.switched_off');

            return $back;
        }
        $move = $this->moveOverview->serviceMove($id);
        if (null === $move || 'failed' !== $move['state']) {
            $this->flash('info', 'inbound_move.flash.service_nothing');

            return $back;
        }
        if (!$this->moveLimiter->create('service-'.$id)->consume()->isAccepted()) {
            $this->flash('warning', 'inbound_move.flash.service_rate_limited');

            return $back;
        }
        $result = $this->moveControls->retryFailed($move['run_id'], true, $id);
        $result['ok']
            ? $this->flash('success', 'inbound_move.flash.service_retried', ['%run%' => $move['run_id']])
            : $this->flash('warning', 'inbound_move.flash.refused', ['%reason%' => $result['message']]);

        return $back;
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
