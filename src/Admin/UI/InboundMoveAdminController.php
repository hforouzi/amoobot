<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Application\InboundMoveActionResult;
use App\Admin\Application\InboundMoveActions;
use App\Entity\AdminUser;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Apply to existing services" on a plan and the controls of its run, in the web panel (docs/design/plan-inbound-change.md
 * 5.2, 5.6, 6.1-6.3, 8.1; PR P7). PLANS_MANAGE (AdminCrudPermissions). The code is InboundMoveActions, shared with the
 * Mini App admin (P9); this controller adds the pages, the CSRF tokens and the flashes.
 *
 * - The Apply page is a GET: the dry-run counts with the scope options of 6.2, computed locally by the same planner as
 *   the command line (InboundMovePlanner::candidates(), localOnly: no panel call), then a confirmation step (also a
 *   GET). Only its POST (CSRF) creates the run, through InboundMovePlanner::create(), as app:plan:apply-inbounds
 *   --apply does: same scope, same checks, same audit entry, one open run per plan (the database's unique key turns a
 *   double submit into "refused, open run #N"). The run is created planned; the scheduled task inbound-moves (P8)
 *   processes it. Nothing here moves a service in the request.
 * - The run's buttons are POSTs with a CSRF token: pause and cancel (InboundMoveControls, as --pause-run and --cancel),
 *   resume and "retry failed now" (InboundMoveControls: the run is due again, the scheduled task does the pass), and
 *   undo (InboundMoveRunner::undo() under the lock of the command and the task, as --undo: it calls the panel, so it
 *   runs in the request and refuses at once while a pass holds the lock).
 *
 * No GET changes anything; every page shows service ids and inbound titles only (D5: never a link, UUID, token,
 * customer or reseller owner); no order, payment, wallet, user or reseller row is locked or written here.
 */
final class InboundMoveAdminController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF_CREATE = 'inbound-move-create';
    public const CSRF_RUN = 'inbound-move-run';
    public const ACTIONS = InboundMoveActions::ACTIONS;
    /** The dry-run lists show at most this many services each (the counts are always complete). */
    public const LIST_LIMIT = InboundMoveActions::LIST_LIMIT;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMoveActions $actions,
    ) {
    }

    /** The dry run (step 1) and the confirmation (step 2, ?step=confirm). Read only, no panel call. */
    #[AdminRoute(path: '/plan/{id}/apply-inbounds', name: 'plan_apply_inbounds', options: ['requirements' => ['id' => '\d+'], 'methods' => ['GET']])]
    public function apply(Request $request, int $id): Response
    {
        return $this->render('admin/inbound_move/apply.html.twig', $this->actions->applyPage($this->plan($id), $request->query->all(), 'confirm' === $request->query->get('step')));
    }

    /** Creates the run (planned), as --apply does; the scheduled task processes it. */
    #[AdminRoute(path: '/plan/{id}/apply-inbounds/create', name: 'plan_apply_inbounds_create', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function create(Request $request, int $id): RedirectResponse
    {
        $plan = $this->plan($id);
        if (!$this->isCsrfTokenValid(self::CSRF_CREATE.'-'.$id, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->redirectToRoute('admin_plan_apply_inbounds', ['id' => $id] + $this->actions->form($request->request->all(), $plan)[0]['query']);
        }
        $user = $this->getUser();
        $result = $this->actions->create($plan, $request->request->all(), $user instanceof AdminUser ? $user->getId() : null);
        $this->flashAll($result);

        return $result->back ? $this->redirectToRoute('admin_plan_apply_inbounds', ['id' => $id] + $result->query) : $this->planPage($id);
    }

    /** pause | resume | cancel | undo | retry (failed now) on a run; back to its plan's page. */
    #[AdminRoute(path: '/inbound-move-run/{id}/{action}', name: 'inbound_move_run_action', options: ['requirements' => ['id' => '\d+', 'action' => 'pause|resume|cancel|undo|retry'], 'methods' => ['POST']])]
    public function action(Request $request, int $id, string $action): RedirectResponse
    {
        $planId = $this->actions->planOfRun($id) ?? throw new NotFoundHttpException();
        if (!$this->isCsrfTokenValid(self::CSRF_RUN.'-'.$id, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->planPage($planId);
        }
        $this->flashAll($this->actions->act($id, $action));

        return $this->planPage($planId);
    }

    private function flashAll(InboundMoveActionResult $result): void
    {
        foreach ($result->messages as [$level, $key, $parameters]) {
            $this->flash($level, $key, $parameters);
        }
    }

    private function plan(int $id): Plan
    {
        $plan = $this->entityManager->find(Plan::class, $id);
        if (!$plan instanceof Plan) {
            throw new NotFoundHttpException();
        }

        return $plan;
    }

    private function planPage(int $planId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('admin_plan_detail', ['entityId' => $planId]).'#inbound-move');
    }
}
