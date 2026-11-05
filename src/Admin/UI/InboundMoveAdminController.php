<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Provisioning\Application\InboundMoveCandidate;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveLock;
use App\Provisioning\Application\InboundMoveOverview;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Application\SafeText;
use App\Shop\Application\InboundMoveSettings;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Apply to existing services" on a plan and the controls of its run, in the web panel (docs/design/plan-inbound-change.md
 * 5.2, 5.6, 6.1-6.3, 8.1; PR P7). PLANS_MANAGE (AdminCrudPermissions).
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
    public const ACTIONS = ['pause', 'resume', 'cancel', 'undo', 'retry'];
    /** The dry-run lists show at most this many services each (the counts are always complete). */
    public const LIST_LIMIT = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMovePlanner $planner,
        private readonly InboundMoveControls $controls,
        private readonly InboundMoveRunner $runner,
        private readonly InboundMoveLock $lock,
        private readonly InboundMoveSettings $settings,
        private readonly InboundMoveOverview $overview,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The dry run (step 1) and the confirmation (step 2, ?step=confirm). Read only, no panel call. */
    #[AdminRoute(path: '/plan/{id}/apply-inbounds', name: 'plan_apply_inbounds', options: ['requirements' => ['id' => '\d+'], 'methods' => ['GET']])]
    public function apply(Request $request, int $id): Response
    {
        $plan = $this->plan($id);
        [$form, $errors] = $this->form($request->query->all(), $plan);
        $target = $plan->getInbound();
        $candidates = null;
        if ([] === $errors && $target instanceof VpnInbound) {
            $candidates = $this->planner->candidates($plan, $target, $form['scope'], false, null, true);
        }
        $summary = null === $candidates ? null : self::summary($candidates);
        $openRun = $this->planner->openRunId((int) $plan->getId());
        $confirm = 'confirm' === $request->query->get('step') && [] === $errors && null !== $summary && $summary['move'] > 0;

        return $this->render('admin/inbound_move/apply.html.twig', [
            'plan' => $plan,
            'target' => $target,
            'targetProblem' => null === $this->planner->targetProblem($plan) ? null : (null === $target ? 'no_target' : 'target_inactive'),
            'form' => $form,
            'errors' => $errors,
            'summary' => $summary,
            'confirm' => $confirm,
            'enabled' => $this->settings->enabled(),
            'openRun' => $openRun,
            'run' => $this->overview->planRun((int) $plan->getId(), null === $target ? null : (int) $target->getId()),
            'problems' => $this->overview->problems(null, (int) $plan->getId()),
            'fromInbounds' => $this->fromInbounds($plan),
            'listLimit' => self::LIST_LIMIT,
            'defaultGrace' => $this->settings->detachGraceHours(),
        ]);
    }

    /** Creates the run (planned), as --apply does; the scheduled task processes it. */
    #[AdminRoute(path: '/plan/{id}/apply-inbounds/create', name: 'plan_apply_inbounds_create', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function create(Request $request, int $id): RedirectResponse
    {
        $plan = $this->plan($id);
        [$form, $errors] = $this->form($request->request->all(), $plan);
        $back = $this->redirectToRoute('admin_plan_apply_inbounds', ['id' => $id] + $form['query']);
        if (!$this->isCsrfTokenValid(self::CSRF_CREATE.'-'.$id, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $back;
        }
        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->flash('danger', $error);
            }

            return $back;
        }
        if (!$this->settings->enabled()) {
            $this->flash('danger', 'inbound_move.flash.switched_off');

            return $back;
        }
        $open = $this->planner->openRunId((int) $plan->getId());
        if (null !== $open) {
            $this->flash('warning', 'inbound_move.flash.open_run', ['%run%' => $open]);

            return $this->planPage($id);
        }
        $user = $this->getUser();
        $created = $this->planner->create($plan, $form['scope'], $user instanceof AdminUser ? $user->getId() : null, $form['keepOld'], $form['keepOld'] ? null : $form['graceHours']);
        if (is_string($created)) {
            $open = $this->planner->openRunId((int) $plan->getId());
            null !== $open
                ? $this->flash('warning', 'inbound_move.flash.open_run', ['%run%' => $open])
                : $this->flash('danger', 'inbound_move.flash.refused', ['%reason%' => $created]);

            return null !== $open ? $this->planPage($id) : $back;
        }
        [$runId, $candidates] = $created;
        $summary = self::summary($candidates);
        $this->flash('success', 'inbound_move.flash.created', ['%run%' => $runId, '%move%' => $summary['move'], '%skipped%' => $summary['skipped']]);

        return $this->planPage($id);
    }

    /** pause | resume | cancel | undo | retry (failed now) on a run; back to its plan's page. */
    #[AdminRoute(path: '/inbound-move-run/{id}/{action}', name: 'inbound_move_run_action', options: ['requirements' => ['id' => '\d+', 'action' => 'pause|resume|cancel|undo|retry'], 'methods' => ['POST']])]
    public function action(Request $request, int $id, string $action): RedirectResponse
    {
        $planId = $this->entityManager->getConnection()->fetchOne("SELECT plan_id FROM inbound_move_run WHERE id = ? AND kind = 'primary'", [$id]);
        if (false === $planId || null === $planId) {
            throw new NotFoundHttpException();
        }
        $planId = (int) $planId;
        $back = $this->planPage($planId);
        if (!$this->isCsrfTokenValid(self::CSRF_RUN.'-'.$id, (string) $request->request->get('_token'))) {
            $this->flash('danger', 'flash.csrf_expired');

            return $back;
        }
        $enabled = $this->settings->enabled();
        if ('undo' === $action) {
            $this->undo($id, $planId, $enabled);

            return $back;
        }
        if (!$enabled && in_array($action, ['resume', 'retry'], true)) {
            $this->flash('danger', 'inbound_move.flash.switched_off');

            return $back;
        }
        if ('cancel' === $action) {
            $cancel = $this->controls->cancel($id);
            match (true) {
                !$cancel['ok'] => $this->flash('warning', 'inbound_move.flash.refused', ['%reason%' => $cancel['message']]),
                $cancel['open'] => $this->flash('warning', 'inbound_move.flash.cancelled_open', ['%run%' => $id, '%count%' => $cancel['cancelled']]),
                default => $this->flash('success', 'inbound_move.flash.cancelled', ['%run%' => $id, '%count%' => $cancel['cancelled']]),
            };

            return $back;
        }
        if ('retry' === $action) {
            $retry = $this->controls->retryFailed($id, $enabled);
            $retry['ok']
                ? $this->flash('success', 'inbound_move.flash.retried', ['%run%' => $id, '%count%' => $retry['due']])
                : $this->flash('warning', 'inbound_move.flash.refused', ['%reason%' => $retry['message']]);

            return $back;
        }
        $result = 'pause' === $action ? $this->controls->pause($id) : $this->controls->resume($id, $enabled);
        $result['ok']
            ? $this->flash('success', 'inbound_move.flash.'.('pause' === $action ? 'paused' : 'resumed'), ['%run%' => $id])
            : $this->flash('warning', 'inbound_move.flash.refused', ['%reason%' => $result['message']]);

        return $back;
    }

    /** --undo, in the request: the same lock as the command and the scheduled task (refused at once while one holds it). */
    private function undo(int $runId, int $planId, bool $enabled): void
    {
        if (!$enabled) {
            $this->flash('danger', 'inbound_move.flash.switched_off');

            return;
        }
        $held = $this->lock->acquire();
        if (null === $held) {
            $this->flash('warning', 'inbound_move.flash.locked');

            return;
        }
        // A step must not be cut by a closed browser tab; an interrupted undo is continued by pressing Undo again.
        ignore_user_abort(true);
        set_time_limit(0);
        try {
            $pass = $this->runner->undo($runId);
        } catch (\Throwable $e) {
            $this->logger->error('inbound_move_web_undo_failed', ['run_id' => $runId, 'error' => SafeText::of($e->getMessage(), '', 255, $this->planner->panelSecrets()), 'class' => $e::class]);
            $this->flash('danger', 'inbound_move.flash.undo_failed');

            return;
        } finally {
            $held->release();
        }
        if (InboundMovePass::RAN !== $pass->outcome) {
            $this->flash('warning', 'inbound_move.flash.refused', ['%reason%' => (string) $pass->message]);

            return;
        }
        $complete = 0 === $pass->failed && 0 === $pass->busy;
        $this->flash($complete && 0 === $pass->alreadyRetired ? 'success' : 'warning', 'inbound_move.flash.undone', [
            '%run%' => $runId, '%undone%' => $pass->undone, '%failed%' => $pass->failed, '%skipped%' => $pass->skipped, '%busy%' => $pass->busy, '%cancelled%' => $pass->cancelled,
        ]);
        if ($pass->alreadyRetired > 0) {
            $this->flash('warning', 'inbound_move.undo_already_retired', ['%count%' => $pass->alreadyRetired, '%plan%' => $planId]);
        }
        if (!$complete) {
            $this->flash('warning', 'inbound_move.flash.undo_again', ['%run%' => $runId]);
        }
    }

    /**
     * The scope options of 6.2 from the query (pages) or the form (create), validated as the command validates them.
     *
     * @param array<string, mixed> $input the query (pages) or the form (create)
     *
     * @return array{array{scope: InboundMoveScope, service: int|null, fromInbound: int|null, includeSuspended: bool, includeExpired: bool, includeTrials: bool, keepOld: bool, graceHours: int, query: array<string, string>}, list<string>}
     */
    private function form(array $input, Plan $plan): array
    {
        $errors = [];
        $int = static function (string $name) use ($input, &$errors): ?int {
            $value = is_scalar($input[$name] ?? null) ? trim((string) $input[$name]) : '';
            if ('' === $value) {
                return null;
            }
            if (1 !== preg_match('/^\d{1,9}$/D', $value)) {
                $errors[] = 'inbound_move.error.'.$name;

                return null;
            }

            return (int) $value;
        };
        $service = $int('service');
        $fromInbound = $int('from_inbound');
        $grace = $int('grace_hours');
        $flag = static fn (string $name): bool => '1' === ($input[$name] ?? null);
        if (0 === $service || 0 === $fromInbound) {
            $errors[] = 0 === $service ? 'inbound_move.error.service' : 'inbound_move.error.from_inbound';
        }
        if (null !== $grace && ($grace < InboundMoveSettings::MIN_DETACH_GRACE_HOURS || $grace > InboundMoveSettings::MAX_DETACH_GRACE_HOURS)) {
            $errors[] = 'inbound_move.error.grace_hours';
        }
        if (null !== $service && $service > 0 && $this->planner->planIdOf($service) !== (int) $plan->getId()) {
            $errors[] = 'inbound_move.error.service_of_plan';
        }
        $graceHours = null === $grace || $grace > InboundMoveSettings::MAX_DETACH_GRACE_HOURS ? $this->settings->detachGraceHours() : $grace;
        $form = [
            'service' => $service ?: null,
            'fromInbound' => $fromInbound ?: null,
            'includeSuspended' => $flag('include_suspended'),
            'includeExpired' => $flag('include_expired'),
            'includeTrials' => $flag('include_trials'),
            'keepOld' => $flag('keep_old'),
            'graceHours' => $graceHours,
        ];
        $form['scope'] = new InboundMoveScope($form['service'], $form['fromInbound'], $form['includeSuspended'], $form['includeExpired'], $form['includeTrials']);
        $form['query'] = array_filter([
            'service' => null === $form['service'] ? '' : (string) $form['service'],
            'from_inbound' => null === $form['fromInbound'] ? '' : (string) $form['fromInbound'],
            'include_suspended' => $form['includeSuspended'] ? '1' : '',
            'include_expired' => $form['includeExpired'] ? '1' : '',
            'include_trials' => $form['includeTrials'] ? '1' : '',
            'keep_old' => $form['keepOld'] ? '1' : '',
            'grace_hours' => (string) $graceHours,
        ], static fn (string $v): bool => '' !== $v);

        return [$form, array_values(array_unique($errors))];
    }

    /**
     * @param list<InboundMoveCandidate> $candidates
     *
     * @return array{total: int, move: int, skipped: int, byReason: array<string, int>, unchecked: int, moving: list<InboundMoveCandidate>, skippedList: list<InboundMoveCandidate>}
     */
    private static function summary(array $candidates): array
    {
        $moving = array_values(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_MOVE === $c->action));
        $skipped = array_values(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_SKIP === $c->action));
        $byReason = [];
        foreach ($skipped as $candidate) {
            $byReason[(string) $candidate->skipReason] = ($byReason[(string) $candidate->skipReason] ?? 0) + 1;
        }
        ksort($byReason);

        return [
            'total' => count($candidates),
            'move' => count($moving),
            'skipped' => count($skipped),
            'byReason' => $byReason,
            // The protocol rules needing the panel's inbounds/options were not checked (the run's creation checks them).
            'unchecked' => count(array_filter($moving, static fn (InboundMoveCandidate $c): bool => null !== $c->panelNote)),
            'moving' => array_slice($moving, 0, self::LIST_LIMIT),
            'skippedList' => array_slice($skipped, 0, self::LIST_LIMIT),
        ];
    }

    /**
     * The inbounds the plan's services are on now, other than the target (the "only services on inbound X" choice).
     *
     * @return list<array{id: int, title: string}>
     */
    private function fromInbounds(Plan $plan): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT DISTINCT i.id, i.title FROM vpn_service s JOIN `order` o ON o.id = s.order_id JOIN vpn_inbound i ON i.id = s.inbound_id
             WHERE o.plan_id = ? AND s.status <> 'deleted' AND (? IS NULL OR i.id <> ?) ORDER BY i.id LIMIT 50",
            [$plan->getId(), $plan->getInbound()?->getId(), $plan->getInbound()?->getId()],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => mb_substr((string) $r['title'], 0, 60)], $rows);
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
