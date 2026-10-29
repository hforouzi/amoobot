<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\ServiceClaim;
use App\Entity\ServiceOwnershipEvent;
use App\Entity\VpnService;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Application\ServiceOwnership;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\CreatedClaimLink;
use App\Security\AdminActor;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Claim links in the web panel (docs/design/reseller-and-claim.md, S2). SERVICES_VIEW to look: every link (filter by
 * status) and, per service, its links and ownership history. The writes need SERVICES_MANAGE (AdminCrudPermissions::
 * ROUTES), are POST only with a CSRF token and audited: make a link for a service (support case; its token is shown on
 * the answer page once and never again), revoke an open link, and "Return to reseller" (with a reason) when the wrong
 * person claimed a service. Tokens are never stored, so no page can show one later. A write that meets a concurrent
 * one (a claim, a reset, a settlement of the service) is retried a few times and otherwise answered with a "busy, try
 * again" message, never a database error page (ClaimTransactions).
 */
final class ClaimAdminController extends AbstractController
{
    use TranslatedFlashes;

    private const PAGE_SIZE = 30;
    private const CSRF = 'claim_admin';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClaimLinks $claimLinks,
        private readonly ServiceOwnership $ownership,
        private readonly ResellerSettings $settings,
    ) {
    }

    #[AdminRoute(path: '/claims', name: 'claims')]
    public function index(Request $request): Response
    {
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $status = (string) $request->query->get('status', '');
        $status = in_array($status, ClaimStatus::ALL, true) ? $status : '';
        $query = $this->entityManager->getRepository(ServiceClaim::class)->createQueryBuilder('c')
            ->join('c.service', 's')->addSelect('s')
            ->join('c.fromUser', 'f')->addSelect('f')
            ->leftJoin('c.reseller', 'r')->addSelect('r')
            ->leftJoin('r.user', 'ru')->addSelect('ru')
            ->leftJoin('c.claimedByUser', 'cu')->addSelect('cu')
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1)
            ->setFirstResult(($page - 1) * self::PAGE_SIZE);
        if ('' !== $status) {
            $query->where('c.status = :status')->setParameter('status', $status);
        }
        /** @var list<ServiceClaim> $rows */
        $rows = $query->getQuery()->getResult();

        return $this->render('admin/claim/index.html.twig', [
            'claims' => array_slice($rows, 0, self::PAGE_SIZE),
            'hasNext' => count($rows) > self::PAGE_SIZE,
            'page' => $page,
            'status' => $status,
            'statuses' => ClaimStatus::ALL,
            'enabled' => $this->settings->claimsEnabled(),
            'csrf' => self::CSRF,
        ]);
    }

    #[AdminRoute(path: '/claims/service/{id}', name: 'claims_service', options: ['requirements' => ['id' => '\d+']])]
    public function service(int $id): Response
    {
        $service = $this->findService($id);

        return $this->servicePage($service);
    }

    /** An admin-made link (support case): the answer page shows it once. */
    #[AdminRoute(path: '/claims/service/{id}/link', name: 'claims_create', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function create(Request $request, int $id): Response
    {
        $service = $this->findService($id);
        if (!$this->validToken($request)) {
            return $this->back($service, 'flash.csrf_expired', 'danger');
        }
        try {
            $link = $this->claimLinks->createForAdmin($service, $this->admin());
        } catch (ClaimException $e) {
            return $this->back($service, match ($e->error) {
                ClaimException::FEATURE_OFF => 'claim_admin.error.feature_off',
                ClaimException::BUSY => 'claim_admin.error.busy',
                default => 'claim_admin.error.not_claimable',
            }, 'danger');
        }
        $response = $this->servicePage($service, $link);
        // The token is on this page only: never cached, never stored.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[AdminRoute(path: '/claims/{id}/revoke', name: 'claim_revoke', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function revoke(Request $request, int $id): RedirectResponse
    {
        $claim = $this->entityManager->getRepository(ServiceClaim::class)->find($id) ?? throw new NotFoundHttpException();
        if (!$this->validToken($request)) {
            return $this->back($claim->getService(), 'flash.csrf_expired', 'danger');
        }
        try {
            $revoked = $this->claimLinks->revokeByAdmin($id, $this->admin());
        } catch (ClaimException $e) {
            // The link is being used right now (its row stayed locked): nothing changed.
            return $this->back($claim->getService(), 'claim_admin.error.busy', 'danger');
        }

        return $this->back($claim->getService(), $revoked ? 'claim_admin.flash.revoked' : 'claim_admin.flash.not_open', $revoked ? 'success' : 'warning');
    }

    /** The wrong person claimed it: back to the reseller who sold it, with a reason (audited claim.revert). */
    #[AdminRoute(path: '/claims/service/{id}/return', name: 'claims_return', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function returnToReseller(Request $request, int $id): RedirectResponse
    {
        $service = $this->findService($id);
        if (!$this->validToken($request)) {
            return $this->back($service, 'flash.csrf_expired', 'danger');
        }
        try {
            $this->ownership->returnToReseller($service, (string) $request->request->get('reason', ''), $this->admin());
        } catch (ClaimException $e) {
            return $this->back($service, match ($e->error) {
                ClaimException::REASON_REQUIRED => 'claim_admin.error.reason',
                ClaimException::BUSY => 'claim_admin.error.busy',
                default => 'claim_admin.error.not_returnable',
            }, 'danger');
        }

        return $this->back($service, 'claim_admin.flash.returned', 'success');
    }

    private function servicePage(VpnService $service, ?CreatedClaimLink $created = null): Response
    {
        /** @var list<ServiceClaim> $claims */
        $claims = $this->entityManager->getRepository(ServiceClaim::class)->createQueryBuilder('c')
            ->leftJoin('c.claimedByUser', 'cu')->addSelect('cu')
            ->where('c.service = :service')->setParameter('service', $service)
            ->orderBy('c.id', 'DESC')
            ->getQuery()->getResult();
        /** @var list<ServiceOwnershipEvent> $events */
        $events = $this->entityManager->getRepository(ServiceOwnershipEvent::class)->createQueryBuilder('e')
            ->join('e.fromUser', 'f')->addSelect('f')
            ->join('e.toUser', 't')->addSelect('t')
            ->where('e.service = :service')->setParameter('service', $service)
            ->orderBy('e.id', 'DESC')
            ->getQuery()->getResult();

        return $this->render('admin/claim/service.html.twig', [
            'service' => $service,
            'claims' => $claims,
            'events' => $events,
            'created' => $created,
            'claimProblem' => $this->settings->claimsEnabled() ? $this->claimLinks->serviceProblem($service, null) : 'claims_disabled',
            'returnProblem' => $this->ownership->returnProblem($service),
            'enabled' => $this->settings->claimsEnabled(),
            'csrf' => self::CSRF,
        ]);
    }

    private function findService(int $id): VpnService
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($id);
        if (!$service instanceof VpnService) {
            throw new NotFoundHttpException();
        }
        $this->entityManager->refresh($service);

        return $service;
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF, $token);
    }

    private function admin(): AdminActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return AdminActor::fromAdminUser($user);
    }

    private function back(VpnService $service, string $message, string $type): RedirectResponse
    {
        $this->flash($type, $message);

        return $this->redirectToRoute('admin_claims_service', ['id' => $service->getId()]);
    }
}
