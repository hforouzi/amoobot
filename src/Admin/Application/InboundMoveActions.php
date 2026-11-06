<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\InboundMoveRun;
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
use App\Shop\Application\MultiInboundSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * "Apply to existing services" and the controls of a run, for every admin surface: the web panel (P7) and the Mini App
 * admin (P9) call this, and this calls what app:plan:apply-inbounds calls (docs/design/plan-inbound-change.md 5.2,
 * 5.6, 6.1-6.3, 8.1). One implementation:
 *
 * - applyPage(): the dry run with the scope options of 6.2, by the command's planner (InboundMovePlanner::candidates(),
 *   localOnly: no panel call), and what the page shows around it. Read only.
 * - create(): InboundMovePlanner::create(), as --apply (same scope, checks, audit entry, one open run per plan: the
 *   database's unique key turns a double submit into "refused, open run #N"). The run is created planned; the
 *   scheduled task inbound-moves processes it.
 * - act(): pause, cancel (InboundMoveControls, as --pause-run / --cancel), resume and "retry failed now"
 *   (InboundMoveControls: due again, the scheduled task does the pass), undo (InboundMoveRunner::undo() under the lock
 *   of the command and the task, as --undo; it calls the panel, so it runs in the request).
 * - retryService(): one service's failed move due again (limiter admin_service_move, per service, shared by the
 *   surfaces).
 *
 * A run has a kind (docs/design/plan-inbound-add-remove.md 9.1, 9.2, PR P11d): "primary" as above, or "add" / "remove" with
 * a target inbound, by InboundMovePlanner::createAdd() / createRemove(), as --kind=add|remove --inbound=<id> --apply. form()
 * takes the same options and refuses what the command refuses for that kind, the plan rule is the planner's, and neither
 * kind can be created with plans.inbound_move_enabled or plans.multi_inbound_enabled off. Undo needs only the first
 * (6.3). A run never writes the plan (section 4).
 *
 * The caller checks the permission and its CSRF protection, then shows the messages (keys of the admin domain, with
 * their parameters as plain values: escape them for HTML). Ids, inbound titles, states and counts only (D5); no order,
 * payment, wallet, user or reseller row is locked or written here.
 */
final class InboundMoveActions
{
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
        private readonly MultiInboundSettings $multiInbound,
        private readonly InboundMoveOverview $overview,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_service_move')]
        private readonly RateLimiterFactory $serviceLimiter,
    ) {
    }

    /**
     * What the Apply page shows: the dry run (or, with $confirmStep, the confirmation), the run block and the choices.
     *
     * @param array<string, mixed> $input the page's query
     *
     * @return array<string, mixed>
     */
    public function applyPage(Plan $plan, array $input, bool $confirmStep): array
    {
        [$form, $errors] = $this->form($input, $plan);
        $kind = $form['kind'];
        $primary = InboundMoveRun::KIND_PRIMARY === $kind;
        $planInbound = $plan->getInbound();
        $target = $primary ? $planInbound : $form['target'];
        $summary = null;
        if ([] === $errors && $target instanceof VpnInbound) {
            $summary = self::summary(match ($kind) {
                InboundMoveRun::KIND_ADD => $this->planner->addCandidates($plan, $target, $form['scope'], false, null, true),
                InboundMoveRun::KIND_REMOVE => $this->planner->removeCandidates($plan, $target, $form['scope']),
                default => $this->planner->candidates($plan, $target, $form['scope'], false, null, true),
            });
        }
        $openRun = $this->planner->openRunId((int) $plan->getId());
        $enabled = $this->settings->enabled();
        $multiEnabled = $this->multiInbound->enabled();
        $backing = $this->planner->isBackingPlan($plan);
        $problem = $primary
            ? (null === $this->planner->targetProblem($plan) ? null : (null === $target ? 'no_target' : 'target_inactive'))
            : $this->kindProblem($plan, $kind, $target);

        return [
            'plan' => $plan,
            'kind' => $kind,
            'kinds' => $enabled && $multiEnabled && !$backing ? InboundMoveRun::RUNNABLE_KINDS : [InboundMoveRun::KIND_PRIMARY],
            'target' => $target,
            'targets' => $primary || $backing ? [] : $this->kindTargets($plan, $kind),
            'targetProblem' => $problem,
            'form' => $form,
            'errors' => $errors,
            'summary' => $summary,
            'inbounds' => $primary || null === $summary || !$target instanceof VpnInbound ? [] : $this->localInbounds($summary['moving'], $kind, $target),
            'confirm' => $confirmStep && [] === $errors && null !== $summary && $summary['move'] > 0,
            'enabled' => $enabled,
            'multiOff' => !$primary && !$multiEnabled,
            'canCreate' => $enabled && ($primary || $multiEnabled) && null === $openRun && null === $problem,
            'openRun' => $openRun,
            'run' => $this->overview->planRun((int) $plan->getId(), null === $planInbound ? null : (int) $planInbound->getId()),
            'problems' => $this->overview->problems(null, (int) $plan->getId()),
            'fromInbounds' => $this->fromInbounds($plan, $kind),
            'listLimit' => self::LIST_LIMIT,
            'defaultGrace' => $this->settings->detachGraceHours(),
        ];
    }

    /**
     * Creates the plan's run (planned), as --apply does. back: the result belongs on the Apply page (refused input),
     * else on the plan's page.
     *
     * @param array<string, mixed> $input the confirmation's fields (the scope options)
     */
    public function create(Plan $plan, array $input, ?int $adminId): InboundMoveActionResult
    {
        [$form, $errors] = $this->form($input, $plan);
        $kind = $form['kind'];
        $target = $form['target'];
        $primary = InboundMoveRun::KIND_PRIMARY === $kind;
        if (!$primary && !$target instanceof VpnInbound && [] === $errors) {
            $errors[] = 'inbound_move.error.inbound';
        }
        if ([] !== $errors) {
            return new InboundMoveActionResult(false, true, array_map(static fn (string $error): array => ['danger', $error, []], $errors), $form['query']);
        }
        if (!$this->settings->enabled()) {
            return new InboundMoveActionResult(false, true, [['danger', 'inbound_move.flash.switched_off', []]], $form['query']);
        }
        if (!$primary && !$this->multiInbound->enabled()) {
            return new InboundMoveActionResult(false, true, [['danger', 'inbound_move.flash.multi_switched_off', []]], $form['query']);
        }
        $problem = $primary ? null : $this->kindProblem($plan, $kind, $target);
        if (null !== $problem) {
            return new InboundMoveActionResult(false, true, [['danger', 'inbound_move.apply.'.$problem, ['%inbound%' => mb_substr((string) $target?->getTitle(), 0, 60)]]], $form['query']);
        }
        $open = $this->planner->openRunId((int) $plan->getId());
        if (null !== $open) {
            return new InboundMoveActionResult(false, false, [['warning', 'inbound_move.flash.open_run', ['%run%' => $open]]], $form['query']);
        }
        $created = match (true) {
            $primary => $this->planner->create($plan, $form['scope'], $adminId, $form['keepOld'], $form['keepOld'] ? null : $form['graceHours']),
            InboundMoveRun::KIND_ADD === $kind && $target instanceof VpnInbound => $this->planner->createAdd($plan, $target, $form['scope'], $adminId),
            $target instanceof VpnInbound => $this->planner->createRemove($plan, $target, $form['scope'], $form['graceHours'], $adminId),
            default => 'no target',
        };
        if (is_string($created)) {
            $open = $this->planner->openRunId((int) $plan->getId());

            return null !== $open
                ? new InboundMoveActionResult(false, false, [['warning', 'inbound_move.flash.open_run', ['%run%' => $open]]], $form['query'])
                : new InboundMoveActionResult(false, true, [['danger', 'inbound_move.flash.refused', ['%reason%' => $created]]], $form['query']);
        }
        [$runId, $candidates] = $created;
        $summary = self::summary($candidates);

        return new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.created'.self::suffix($kind), ['%run%' => $runId, '%move%' => $summary['move'], '%skipped%' => $summary['skipped']]]], $form['query'], $runId);
    }

    /** The plan of a run the pages can act on (a kind this version knows), or null. */
    public function planOfRun(int $runId): ?int
    {
        $planId = $this->entityManager->getConnection()->fetchOne(
            'SELECT plan_id FROM inbound_move_run WHERE id = ? AND kind IN (?)',
            [$runId, InboundMoveRun::RUNNABLE_KINDS],
            [1 => ArrayParameterType::STRING],
        );

        return false === $planId || null === $planId ? null : (int) $planId;
    }

    /** pause | resume | cancel | undo | retry (failed now) on a run, as the command's controls. */
    public function act(int $runId, string $action): InboundMoveActionResult
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown inbound move action.');
        }
        $enabled = $this->settings->enabled();
        $kind = $this->runKind($runId);
        if ('undo' === $action) {
            return $this->undo($runId, $enabled, $kind);
        }
        if (in_array($action, ['resume', 'retry'], true)) {
            if (!$enabled) {
                return self::refusal('danger', 'inbound_move.flash.switched_off');
            }
            if (InboundMoveRun::KIND_PRIMARY !== $kind && !$this->multiInbound->enabled()) {
                return self::refusal('danger', 'inbound_move.flash.multi_switched_off');
            }
        }
        if ('cancel' === $action) {
            $cancel = $this->controls->cancel($runId);
            $suffix = self::suffix($kind);

            return match (true) {
                !$cancel['ok'] => self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $cancel['message']]),
                $cancel['open'] => new InboundMoveActionResult(true, false, [['warning', 'inbound_move.flash.cancelled_open'.$suffix, ['%run%' => $runId, '%count%' => $cancel['cancelled']]]]),
                default => new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.cancelled'.$suffix, ['%run%' => $runId, '%count%' => $cancel['cancelled']]]]),
            };
        }
        if ('retry' === $action) {
            $retry = $this->controls->retryFailed($runId, $enabled);

            return $retry['ok']
                ? new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.retried', ['%run%' => $runId, '%count%' => $retry['due']]]])
                : self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $retry['message']]);
        }
        $result = 'pause' === $action ? $this->controls->pause($runId) : $this->controls->resume($runId, $enabled);

        return $result['ok']
            ? new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.'.('pause' === $action ? 'paused' : 'resumed'), ['%run%' => $runId]]])
            : self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $result['message']]);
    }

    /** "Retry now" on a service: its failed move is due again (the scheduled task tries it). No panel call. */
    public function retryService(int $serviceId): InboundMoveActionResult
    {
        if (!$this->settings->enabled()) {
            return self::refusal('danger', 'inbound_move.flash.switched_off');
        }
        $move = $this->overview->serviceMove($serviceId);
        if (null === $move || 'failed' !== $move['state']) {
            return self::refusal('info', 'inbound_move.flash.service_nothing');
        }
        if ($move['multi_off']) {
            return self::refusal('danger', 'inbound_move.flash.multi_switched_off');
        }
        if (!$this->serviceLimiter->create('service-'.$serviceId)->consume()->isAccepted()) {
            return self::refusal('warning', 'inbound_move.flash.service_rate_limited', [], true);
        }
        $result = $this->controls->retryFailed($move['run_id'], true, $serviceId);

        return $result['ok']
            ? new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.service_retried', ['%run%' => $move['run_id']]]])
            : self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $result['message']]);
    }

    /**
     * The kind, its target and the scope options of 6.2 from the query (pages) or the form (create), validated as the
     * command validates them: what --kind=add or --kind=remove refuses is refused here. Errors are keys of the admin domain.
     * No inbound chosen is no error here: a page just asks for one, and create() refuses it.
     *
     * @param array<string, mixed> $input
     *
     * @return array{array{kind: string, inbound: int|null, target: VpnInbound|null, scope: InboundMoveScope, service: int|null, fromInbound: int|null, includeSuspended: bool, includeExpired: bool, includeTrials: bool, keepOld: bool, graceHours: int, query: array<string, string>}, list<string>}
     */
    public function form(array $input, Plan $plan): array
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
        $kind = is_string($input['kind'] ?? null) && '' !== $input['kind'] ? $input['kind'] : InboundMoveRun::KIND_PRIMARY;
        if (!in_array($kind, InboundMoveRun::RUNNABLE_KINDS, true)) {
            $errors[] = 'inbound_move.error.kind';
            $kind = InboundMoveRun::KIND_PRIMARY;
        }
        $primary = InboundMoveRun::KIND_PRIMARY === $kind;
        $service = $int('service');
        $fromInbound = $int('from_inbound');
        $grace = $int('grace_hours');
        $inbound = $int('inbound');
        $flag = static fn (string $name): bool => in_array($input[$name] ?? null, ['1', 1, true], true);
        if (0 === $service || 0 === $fromInbound || 0 === $inbound) {
            $errors[] = match (true) {
                0 === $service => 'inbound_move.error.service',
                0 === $fromInbound => 'inbound_move.error.from_inbound',
                default => 'inbound_move.error.inbound',
            };
        }
        $target = null;
        if ($primary && null !== $inbound) {
            $errors[] = 'inbound_move.error.inbound_primary';
        } elseif (!$primary && null !== $inbound && $inbound > 0) {
            $target = $this->entityManager->find(VpnInbound::class, $inbound);
            if (!$target instanceof VpnInbound) {
                $errors[] = 'inbound_move.error.inbound_unknown';
            }
        }
        if (!$primary) {
            if ($flag('keep_old')) {
                $errors[] = 'inbound_move.error.keep_old_kind';
            }
            if ($flag('include_trials')) {
                $errors[] = 'inbound_move.error.include_trials_kind';
            }
            if (InboundMoveRun::KIND_ADD === $kind && null !== $grace) {
                $errors[] = 'inbound_move.error.grace_add';
            }
            if (InboundMoveRun::KIND_REMOVE === $kind && null !== $fromInbound) {
                $errors[] = 'inbound_move.error.from_inbound_remove';
            }
        }
        if (null !== $grace && ($grace < InboundMoveSettings::MIN_DETACH_GRACE_HOURS || $grace > InboundMoveSettings::MAX_DETACH_GRACE_HOURS)) {
            $errors[] = 'inbound_move.error.grace_hours';
        }
        if (null !== $service && $service > 0 && $this->planner->planIdOf($service) !== (int) $plan->getId()) {
            $errors[] = 'inbound_move.error.service_of_plan';
        }
        // A remove's default is 0, and the move's setting is not read for it (docs/design/plan-inbound-add-remove.md 6.5).
        $defaultGrace = $primary ? $this->settings->detachGraceHours() : 0;
        $graceHours = null === $grace || $grace > InboundMoveSettings::MAX_DETACH_GRACE_HOURS ? $defaultGrace : $grace;
        $form = [
            'kind' => $kind,
            'inbound' => $inbound ?: null,
            'target' => $target instanceof VpnInbound ? $target : null,
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
            'kind' => $primary ? '' : $kind,
            'inbound' => $primary || null === $form['inbound'] ? '' : (string) $form['inbound'],
            'service' => null === $form['service'] ? '' : (string) $form['service'],
            'from_inbound' => null === $form['fromInbound'] ? '' : (string) $form['fromInbound'],
            'include_suspended' => $form['includeSuspended'] ? '1' : '',
            'include_expired' => $form['includeExpired'] ? '1' : '',
            'include_trials' => $form['includeTrials'] ? '1' : '',
            'keep_old' => $form['keepOld'] ? '1' : '',
            'grace_hours' => InboundMoveRun::KIND_ADD === $kind ? '' : (string) $graceHours,
        ], static fn (string $v): bool => '' !== $v);

        return [$form, array_values(array_unique($errors))];
    }

    /**
     * @param list<InboundMoveCandidate> $candidates
     *
     * @return array{total: int, move: int, skipped: int, byReason: array<string, int>, unchecked: int, moving: list<InboundMoveCandidate>, skippedList: list<InboundMoveCandidate>}
     */
    public static function summary(array $candidates): array
    {
        $moving = array_values(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_SKIP !== $c->action));
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

    /** --undo, in the request: the same lock as the command and the scheduled task (refused at once while one holds it). */
    private function undo(int $runId, bool $enabled, string $kind): InboundMoveActionResult
    {
        if (!$enabled) {
            return self::refusal('danger', 'inbound_move.flash.switched_off');
        }
        $held = $this->lock->acquire();
        if (null === $held) {
            return self::refusal('warning', 'inbound_move.flash.locked');
        }
        // A step must not be cut by a closed page; an interrupted undo is continued by pressing Undo again.
        ignore_user_abort(true);
        set_time_limit(0);
        try {
            $pass = $this->runner->undo($runId);
        } catch (\Throwable $e) {
            $this->logger->error('inbound_move_web_undo_failed', ['run_id' => $runId, 'error' => SafeText::of($e->getMessage(), '', 255, $this->planner->panelSecrets()), 'class' => $e::class]);

            return self::refusal('danger', 'inbound_move.flash.undo_failed');
        } finally {
            $held->release();
        }
        if (InboundMovePass::RAN !== $pass->outcome) {
            return self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => (string) $pass->message]);
        }
        $complete = 0 === $pass->failed && 0 === $pass->busy;
        $messages = [[$complete && 0 === $pass->alreadyRetired ? 'success' : 'warning', 'inbound_move.flash.undone'.self::suffix($kind), [
            '%run%' => $runId, '%undone%' => $pass->undone, '%failed%' => $pass->failed, '%skipped%' => $pass->skipped, '%busy%' => $pass->busy, '%cancelled%' => $pass->cancelled, '%left%' => $pass->leftAlone,
        ]]];
        if ($pass->alreadyRetired > 0) {
            $messages[] = ['warning', 'inbound_move.undo_already_retired', ['%count%' => $pass->alreadyRetired, '%plan%' => (int) $this->planOfRun($runId)]];
        }
        if (!$complete) {
            $messages[] = ['warning', 'inbound_move.flash.undo_again', ['%run%' => $runId]];
        }

        return new InboundMoveActionResult(true, false, $messages);
    }

    /**
     * The end of a key of the admin domain for a kind: nothing for a primary run, "_add" or "_remove" otherwise.
     */
    private static function suffix(string $kind): string
    {
        return InboundMoveRun::KIND_PRIMARY === $kind ? '' : '_'.$kind;
    }

    private function runKind(int $runId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT kind FROM inbound_move_run WHERE id = ?', [$runId]);
    }

    /**
     * Why $plan refuses an add or a remove of $target, as the end of a key of the admin domain (inbound_move.apply.*), or
     * null: the planner's rule. A backing plan refuses either kind, with or without a target.
     */
    private function kindProblem(Plan $plan, string $kind, ?VpnInbound $target): ?string
    {
        $issue = match (true) {
            $this->planner->isBackingPlan($plan) => InboundMovePlanner::TARGET_BACKING,
            !$target instanceof VpnInbound => null,
            InboundMoveRun::KIND_ADD === $kind => $this->planner->addTargetIssue($plan, $target),
            default => $this->planner->removeTargetIssue($plan, $target),
        };

        return null === $issue ? null : $kind.'_'.$issue;
    }

    /**
     * What the target chooser of an add or a remove offers (docs/design/plan-inbound-add-remove.md 9.1), by the plan rule
     * of section 4. add: the plan's active additional inbounds on the panel of its primary that some service lacks.
     * remove: the inbounds that services of the plan hold and the plan no longer has, active or not. Local, one query.
     *
     * @return list<array{id: int, title: string, active: bool}>
     */
    private function kindTargets(Plan $plan, string $kind): array
    {
        $planId = (int) $plan->getId();
        $primaryId = $plan->getInbound()?->getId();
        $rows = InboundMoveRun::KIND_ADD === $kind
            ? $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT i.id, i.title, i.is_active FROM plan_additional_inbound a JOIN vpn_inbound i ON i.id = a.inbound_id
                 WHERE a.plan_id = ? AND i.is_active = 1 AND i.panel_id = ?
                   AND EXISTS (SELECT 1 FROM vpn_service s JOIN `order` o ON o.id = s.order_id
                               WHERE o.plan_id = a.plan_id AND s.status <> 'deleted' AND (s.inbound_id IS NULL OR s.inbound_id <> i.id)
                                 AND NOT EXISTS (SELECT 1 FROM vpn_service_inbound r WHERE r.service_id = s.id AND r.inbound_id = i.id AND r.status = 'attached'))
                 ORDER BY i.id LIMIT 50",
                [$planId, $plan->getInbound()?->getPanel()->getId()],
            )
            : $this->entityManager->getConnection()->fetchAllAssociative(
                "SELECT DISTINCT i.id, i.title, i.is_active FROM vpn_service s JOIN `order` o ON o.id = s.order_id
                 JOIN vpn_service_inbound r ON r.service_id = s.id AND r.status <> 'detached' JOIN vpn_inbound i ON i.id = r.inbound_id
                 WHERE o.plan_id = ? AND s.status <> 'deleted' AND (? IS NULL OR i.id <> ?)
                   AND NOT EXISTS (SELECT 1 FROM plan_additional_inbound a WHERE a.plan_id = o.plan_id AND a.inbound_id = i.id)
                 ORDER BY i.id LIMIT 50",
                [$planId, $primaryId, $primaryId],
            );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => mb_substr((string) $r['title'], 0, 60), 'active' => 1 === (int) $r['is_active']], $rows);
    }

    /**
     * What the dry run's list shows for an add or a remove: the service's inbounds now and after, by title, from the
     * database only (the page asks no panel). Two queries, for the listed services.
     *
     * @param list<InboundMoveCandidate> $listed
     *
     * @return array<int, array{now: list<string>, after: list<string>}>
     */
    private function localInbounds(array $listed, string $kind, VpnInbound $target): array
    {
        $ids = array_map(static fn (InboundMoveCandidate $c): int => $c->serviceId, $listed);
        if ([] === $ids) {
            return [];
        }
        $db = $this->entityManager->getConnection();
        $primaries = $db->fetchAllAssociative('SELECT s.id AS service_id, i.id, i.title FROM vpn_service s JOIN vpn_inbound i ON i.id = s.inbound_id WHERE s.id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        $rows = $db->fetchAllAssociative("SELECT r.service_id, i.id, i.title FROM vpn_service_inbound r JOIN vpn_inbound i ON i.id = r.inbound_id WHERE r.service_id IN (?) AND r.status = 'attached' ORDER BY r.id", [$ids], [ArrayParameterType::INTEGER]);
        $now = [];
        foreach ([...$primaries, ...$rows] as $row) {
            $now[(int) $row['service_id']][(int) $row['id']] = mb_substr((string) $row['title'], 0, 60);
        }
        $targetId = (int) $target->getId();
        $title = mb_substr($target->getTitle(), 0, 60);
        $inbounds = [];
        foreach ($ids as $id) {
            $on = $now[$id] ?? [];
            if (InboundMoveRun::KIND_ADD === $kind) {
                $inbounds[$id] = ['now' => array_values($on), 'after' => array_values([$targetId => $title] + $on)];
                continue;
            }
            // A pending or failed row is removed too, so the service counts as holding it.
            $after = $on;
            unset($after[$targetId]);
            $inbounds[$id] = ['now' => array_values([$targetId => $title] + $on), 'after' => array_values($after)];
        }

        return $inbounds;
    }

    /**
     * The inbounds the plan's services are on now, other than the target (the "only services on inbound X" choice). An
     * add's target is not the plan's primary, so that one is a choice too (its --from-inbound).
     *
     * @return list<array{id: int, title: string}>
     */
    private function fromInbounds(Plan $plan, string $kind = InboundMoveRun::KIND_PRIMARY): array
    {
        $except = InboundMoveRun::KIND_ADD === $kind ? null : $plan->getInbound()?->getId();
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT DISTINCT i.id, i.title FROM vpn_service s JOIN `order` o ON o.id = s.order_id JOIN vpn_inbound i ON i.id = s.inbound_id
             WHERE o.plan_id = ? AND s.status <> 'deleted' AND (? IS NULL OR i.id <> ?) ORDER BY i.id LIMIT 50",
            [$plan->getId(), $except, $except],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => mb_substr((string) $r['title'], 0, 60)], $rows);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private static function refusal(string $level, string $key, array $parameters = [], bool $rateLimited = false): InboundMoveActionResult
    {
        return new InboundMoveActionResult(false, false, [[$level, $key, $parameters]], [], null, $rateLimited);
    }
}
