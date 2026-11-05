<?php

declare(strict_types=1);

namespace App\Admin\Application;

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
        $target = $plan->getInbound();
        $summary = null;
        if ([] === $errors && $target instanceof VpnInbound) {
            $summary = self::summary($this->planner->candidates($plan, $target, $form['scope'], false, null, true));
        }
        $openRun = $this->planner->openRunId((int) $plan->getId());

        return [
            'plan' => $plan,
            'target' => $target,
            'targetProblem' => null === $this->planner->targetProblem($plan) ? null : (null === $target ? 'no_target' : 'target_inactive'),
            'form' => $form,
            'errors' => $errors,
            'summary' => $summary,
            'confirm' => $confirmStep && [] === $errors && null !== $summary && $summary['move'] > 0,
            'enabled' => $this->settings->enabled(),
            'openRun' => $openRun,
            'run' => $this->overview->planRun((int) $plan->getId(), null === $target ? null : (int) $target->getId()),
            'problems' => $this->overview->problems(null, (int) $plan->getId()),
            'fromInbounds' => $this->fromInbounds($plan),
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
        if ([] !== $errors) {
            return new InboundMoveActionResult(false, true, array_map(static fn (string $error): array => ['danger', $error, []], $errors), $form['query']);
        }
        if (!$this->settings->enabled()) {
            return new InboundMoveActionResult(false, true, [['danger', 'inbound_move.flash.switched_off', []]], $form['query']);
        }
        $open = $this->planner->openRunId((int) $plan->getId());
        if (null !== $open) {
            return new InboundMoveActionResult(false, false, [['warning', 'inbound_move.flash.open_run', ['%run%' => $open]]], $form['query']);
        }
        $created = $this->planner->create($plan, $form['scope'], $adminId, $form['keepOld'], $form['keepOld'] ? null : $form['graceHours']);
        if (is_string($created)) {
            $open = $this->planner->openRunId((int) $plan->getId());

            return null !== $open
                ? new InboundMoveActionResult(false, false, [['warning', 'inbound_move.flash.open_run', ['%run%' => $open]]], $form['query'])
                : new InboundMoveActionResult(false, true, [['danger', 'inbound_move.flash.refused', ['%reason%' => $created]]], $form['query']);
        }
        [$runId, $candidates] = $created;
        $summary = self::summary($candidates);

        return new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.created', ['%run%' => $runId, '%move%' => $summary['move'], '%skipped%' => $summary['skipped']]]], $form['query'], $runId);
    }

    /** The plan of a run of the primary kind (the runs the pages act on), or null. */
    public function planOfRun(int $runId): ?int
    {
        $planId = $this->entityManager->getConnection()->fetchOne("SELECT plan_id FROM inbound_move_run WHERE id = ? AND kind = 'primary'", [$runId]);

        return false === $planId || null === $planId ? null : (int) $planId;
    }

    /** pause | resume | cancel | undo | retry (failed now) on a run, as the command's controls. */
    public function act(int $runId, string $action): InboundMoveActionResult
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown inbound move action.');
        }
        $enabled = $this->settings->enabled();
        if ('undo' === $action) {
            return $this->undo($runId, $enabled);
        }
        if (!$enabled && in_array($action, ['resume', 'retry'], true)) {
            return self::refusal('danger', 'inbound_move.flash.switched_off');
        }
        if ('cancel' === $action) {
            $cancel = $this->controls->cancel($runId);

            return match (true) {
                !$cancel['ok'] => self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $cancel['message']]),
                $cancel['open'] => new InboundMoveActionResult(true, false, [['warning', 'inbound_move.flash.cancelled_open', ['%run%' => $runId, '%count%' => $cancel['cancelled']]]]),
                default => new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.cancelled', ['%run%' => $runId, '%count%' => $cancel['cancelled']]]]),
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
        if (!$this->serviceLimiter->create('service-'.$serviceId)->consume()->isAccepted()) {
            return self::refusal('warning', 'inbound_move.flash.service_rate_limited', [], true);
        }
        $result = $this->controls->retryFailed($move['run_id'], true, $serviceId);

        return $result['ok']
            ? new InboundMoveActionResult(true, false, [['success', 'inbound_move.flash.service_retried', ['%run%' => $move['run_id']]]])
            : self::refusal('warning', 'inbound_move.flash.refused', ['%reason%' => $result['message']]);
    }

    /**
     * The scope options of 6.2 from the query (pages) or the form (create), validated as the command validates them.
     * Errors are keys of the admin domain.
     *
     * @param array<string, mixed> $input
     *
     * @return array{array{scope: InboundMoveScope, service: int|null, fromInbound: int|null, includeSuspended: bool, includeExpired: bool, includeTrials: bool, keepOld: bool, graceHours: int, query: array<string, string>}, list<string>}
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
        $service = $int('service');
        $fromInbound = $int('from_inbound');
        $grace = $int('grace_hours');
        $flag = static fn (string $name): bool => in_array($input[$name] ?? null, ['1', 1, true], true);
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
    public static function summary(array $candidates): array
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

    /** --undo, in the request: the same lock as the command and the scheduled task (refused at once while one holds it). */
    private function undo(int $runId, bool $enabled): InboundMoveActionResult
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
        $messages = [[$complete && 0 === $pass->alreadyRetired ? 'success' : 'warning', 'inbound_move.flash.undone', [
            '%run%' => $runId, '%undone%' => $pass->undone, '%failed%' => $pass->failed, '%skipped%' => $pass->skipped, '%busy%' => $pass->busy, '%cancelled%' => $pass->cancelled,
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

    /**
     * @param array<string, int|string> $parameters
     */
    private static function refusal(string $level, string $key, array $parameters = [], bool $rateLimited = false): InboundMoveActionResult
    {
        return new InboundMoveActionResult(false, false, [[$level, $key, $parameters]], [], null, $rateLimited);
    }
}
