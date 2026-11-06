<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\Application\InboundMoveActionResult;
use App\Admin\Application\InboundMoveActions;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Apply to existing services" and the run's buttons in the Mini App admin (docs/design/plan-inbound-change.md 5.2,
 * 5.6; PR P9): the same actions as the web panel (P7), through the same code (InboundMoveActions, which calls what
 * app:plan:apply-inbounds calls). Plan screens and buttons need PLANS_MANAGE, the service's "Retry now"
 * SERVICES_MANAGE, as on the web: checked by MiniAppAdminGuard (AdminRoutePermissions) and again here.
 *
 * - The Apply screen is a GET: the dry run with the scope options of 6.2, counted locally (no panel call), and with
 *   ?step=confirm the confirmation (target, counts, phase 1 and phase 2). Its POST creates the run, planned; the
 *   scheduled task inbound-moves processes it. A double tap is one request (the Idempotency-Key of AdminActions), and
 *   the database keeps one open run per plan.
 * - The run's buttons are POSTs (session CSRF token, rate limit and Idempotency-Key of AdminActions); cancel and undo
 *   are confirmed by the server's confirm step first. Resume and "retry failed now" only make the moves due: the
 *   scheduled task works at its next pass.
 * - The service's "Retry now": its failed move is due again; limiter admin_service_move per service, as on the web.
 *
 * Adding or removing one inbound (docs/design/plan-inbound-add-remove.md 9.1, PR P11e) is the same screen with ?kind= and
 * ?inbound=: the kinds, the target rules, both switches and the refusals are InboundMoveActions', so nothing is decided here.
 *
 * No GET changes anything; the screens show service ids and inbound titles only (D5: never a link, UUID, token,
 * customer or reseller owner); no order, payment, wallet, user or reseller row is locked or written here. The audit
 * entries are the command's (plan.inbounds_apply_*), with this admin as the actor (source miniapp).
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminInboundMoveController extends AbstractController
{
    public const RUN_ROUTES = [
        'pause' => 'miniapp_admin_plan_inbound_move_pause',
        'resume' => 'miniapp_admin_plan_inbound_move_resume',
        'retry' => 'miniapp_admin_plan_inbound_move_retry',
        'cancel' => 'miniapp_admin_plan_inbound_move_cancel',
        'undo' => 'miniapp_admin_plan_inbound_move_undo',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMoveActions $moves,
        private readonly AdminActions $actions,
        private readonly AdminRouteAccess $access,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The dry run (step 1) and the confirmation (step 2, ?step=confirm). Read only, no panel call. */
    #[Route('/app/admin/plans/{id}/apply-inbounds', name: 'miniapp_admin_plan_apply_inbounds', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function apply(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $this->require($user, 'miniapp_admin_plan_apply_inbounds');
        $plan = $this->plan($id);
        $query = $request->query->all();
        unset($query['lang'], $query['step']);

        return $this->fragments->render($request, 'miniapp/admin/catalog/plan_apply_inbounds.html.twig', $this->moves->applyPage($plan, self::digits($query), 'confirm' === $request->query->get('step')) + [
            'can' => $this->access->map($user, ['miniapp_admin_plan_apply_inbounds', 'miniapp_admin_plan_apply_inbounds_create', 'miniapp_admin_service', ...array_values(self::RUN_ROUTES)]),
            'runRoutes' => self::RUN_ROUTES,
        ]);
    }

    /** Creates the run (planned), as --apply does; the scheduled task processes it. */
    #[Route('/app/api/admin/plans/{id}/apply-inbounds', name: 'miniapp_admin_plan_apply_inbounds_create', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $this->require($user, 'miniapp_admin_plan_apply_inbounds_create');
        $plan = $this->plan($id);
        $body = self::digits(AdminForms::body($request));

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $plan, $body, $id): array {
            $result = $this->moves->create($plan, $body, $user->admin?->adminUserId);

            return $this->answer($request, $result, $result->ok ? Response::HTTP_OK : ($result->back ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT))
                + ($result->ok ? ['redirect' => $this->generateUrl('miniapp_admin_plan', ['id' => $id]).'#inbound-move'] : []);
        });
    }

    #[Route('/app/api/admin/plans/{id}/inbound-move/pause', name: 'miniapp_admin_plan_inbound_move_pause', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans', 'action' => 'pause'], methods: ['POST'])]
    #[Route('/app/api/admin/plans/{id}/inbound-move/resume', name: 'miniapp_admin_plan_inbound_move_resume', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans', 'action' => 'resume'], methods: ['POST'])]
    #[Route('/app/api/admin/plans/{id}/inbound-move/retry', name: 'miniapp_admin_plan_inbound_move_retry', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans', 'action' => 'retry'], methods: ['POST'])]
    #[Route('/app/api/admin/plans/{id}/inbound-move/cancel', name: 'miniapp_admin_plan_inbound_move_cancel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans', 'action' => 'cancel'], methods: ['POST'])]
    #[Route('/app/api/admin/plans/{id}/inbound-move/undo', name: 'miniapp_admin_plan_inbound_move_undo', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans', 'action' => 'undo'], methods: ['POST'])]
    public function runAction(Request $request, #[CurrentUser] MiniAppUser $user, int $id, string $action): JsonResponse
    {
        $this->require($user, self::RUN_ROUTES[$action] ?? throw new NotFoundHttpException());
        $this->plan($id);
        $runId = AdminText::integer(AdminForms::body($request)['run'] ?? null);
        if (null === $runId || $runId < 1) {
            return AdminActions::json(['error' => 'invalid_input', 'message' => $this->text($request, 'admin.inbound_move.error_run', [], 'miniapp')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->moves->planOfRun($runId) !== $id) {
            throw new NotFoundHttpException();
        }
        $confirm = in_array($action, ['cancel', 'undo'], true) ? $this->text($request, $this->moves->confirmKey($runId, $action)) : null;

        return $this->actions->run($request, $user, $runId, $confirm, function () use ($request, $runId, $action): array {
            $result = $this->moves->act($runId, $action);

            return $this->answer($request, $result, $result->ok ? Response::HTTP_OK : Response::HTTP_CONFLICT);
        });
    }

    /** A service's failed move is due again (the scheduled task tries it). No panel call. */
    #[Route('/app/api/admin/services/{id}/retry-move', name: 'miniapp_admin_service_retry_move', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.services'], methods: ['POST'])]
    public function retryService(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $this->require($user, 'miniapp_admin_service_retry_move');
        if (!$this->entityManager->find(VpnService::class, $id) instanceof VpnService) {
            throw new NotFoundHttpException();
        }

        return $this->actions->run($request, $user, $id, null, function () use ($request, $id): array {
            $result = $this->moves->retryService($id);

            return $this->answer($request, $result, $result->ok ? Response::HTTP_OK : ($result->rateLimited ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT));
        });
    }

    /**
     * The scope's numbers as typed in the Mini App (Persian digits too, as every Mini App admin form), in Latin digits;
     * the validation itself is InboundMoveActions::form(), the web's and the command's.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function digits(array $input): array
    {
        foreach (['service', 'from_inbound', 'inbound', 'grace_hours'] as $name) {
            if (is_string($input[$name] ?? null)) {
                $input[$name] = AdminText::latinDigits($input[$name]);
            }
        }

        return $input;
    }

    /**
     * The result's messages in the admin's language, as one plain text (the app shows it as text, never as HTML).
     *
     * @return array{message: string, status: int}
     */
    private function answer(Request $request, InboundMoveActionResult $result, int $status): array
    {
        $texts = array_map(fn (array $m): string => $this->text($request, $m[1], $m[2]), $result->messages);

        return ['message' => implode("\n", $texts), 'status' => $status];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function text(Request $request, string $key, array $parameters = [], string $domain = 'admin'): string
    {
        return $this->translator->trans($key, $parameters, $domain, MiniAppLocale::fromRequest($request));
    }

    /** The guard checked the route already; checked again here, so a mapping mistake cannot open it. */
    private function require(MiniAppUser $user, string $route): void
    {
        if (!$this->access->allows($user, $route)) {
            throw new AccessDeniedHttpException();
        }
    }

    /** Any plan, as on the web (a trial's backing plan too: its services are the trial's). */
    private function plan(int $id): Plan
    {
        $plan = $this->entityManager->find(Plan::class, $id);
        if (!$plan instanceof Plan) {
            throw new NotFoundHttpException();
        }

        return $plan;
    }
}
