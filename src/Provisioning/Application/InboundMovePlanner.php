<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\InboundMoveRun;
use App\Entity\Plan;
use App\Entity\ServiceInboundMove;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\InboundMoveDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelCallBudget;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\PlanInboundsValidator;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Domain\OrderType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Provisioning\Infrastructure\PanelCredentials;
use Psr\Log\LoggerInterface;

/**
 * Which services of a plan a primary move (kind=primary) concerns, why each one is moved or skipped, and the creation of
 * a run (docs/design/plan-inbound-change.md 4.4, 5.1, 5.2, 6.2; PR P4).
 *
 * "Services of plan P" are vpn_service.order_id → order.plan_id (fact 10); deleted ones never. A service already on the
 * target is not concerned. The target is the plan's primary inbound when the run is created, frozen in the run.
 *
 * skipReason() is the one rule set, used when listing (dry run, run creation) and again by the runner under the
 * service's row lock (4.3 step 1). Checks needing the panel are separate: protocolMix() needs inbounds/options (only for
 * a service with several inbounds, D1), the dry run's panelNote() reads the client (GET only). Both are made outside any
 * transaction, within a PanelCallBudget pass and with recordHealth=false.
 */
final class InboundMovePlanner
{
    public const SKIP_SUSPENDED = 'suspended';
    public const SKIP_EXPIRED = 'expired';
    public const SKIP_TRIAL = 'trial';
    public const SKIP_DELETED = 'deleted';
    public const SKIP_NO_PANEL = 'no_panel';
    public const SKIP_OTHER_PANEL = 'other_panel';
    public const SKIP_NOT_MODERN = 'not_modern';
    public const SKIP_NOT_REAL_PANEL = 'not_real_panel';
    public const SKIP_REMOTE_ID = 'remote_id';
    public const SKIP_IN_OTHER_RUN = 'in_other_run';
    public const SKIP_MOVED_TOO_OFTEN = 'moved_too_often';
    public const SKIP_PROTOCOL_MIX = 'protocol_mix';
    public const SKIP_ALREADY_ON_TARGET = 'already_on_target';
    public const SKIP_INBOUND_CHANGED = 'inbound_changed';
    public const SKIP_TARGET_GONE = 'target_gone';
    public const SKIP_TARGET_INACTIVE = 'target_inactive';

    /** Flap guard (6.2, 8.1): at most this many moves per service within MOVE_WINDOW_HOURS. */
    public const MAX_MOVES_PER_WINDOW = 3;
    public const MOVE_WINDOW_HOURS = 24;
    /** Time limit of one service's panel step (5.3): its GET, attach, GET and subLinks, or a dry run's read. */
    public const STEP_BUDGET_SECONDS = 30;
    /** A dry run stops reading a panel after this many failed reads in a row (as InboundAttachScheduler). */
    public const MAX_READ_FAILURES_IN_A_ROW = InboundAttachScheduler::MAX_CONSECUTIVE_FAILURES;
    public const SOURCE = 'inbound_move';

    /** States of a move that is not finished: another run must not take the service meanwhile (6.2). */
    private const UNFINISHED_SQL = "(m.state IN ('planned', 'attaching', 'detaching') OR (m.state = 'switched' AND m.detach_after IS NOT NULL) OR (m.state = 'failed' AND m.next_attempt_at IS NOT NULL))";

    /** @var array<int, array<string, array<string, mixed>>|null> panel id => inbounds/options by remote id (null: not readable) */
    private array $options = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly ModernClientsApi $api,
        private readonly InboundMoveSettings $settings,
        private readonly PanelCallBudget $budget,
        private readonly AdminAuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly PanelCredentials $credentials,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser = new Sanaei3xuiRemoteIdParser(),
    ) {
    }

    /**
     * The panel's token and password (all panels' when none is given), only to remove them from an error text
     * (SafeText): never shown, stored or logged.
     *
     * @return list<string>
     */
    public function panelSecrets(?VpnPanel $panel = null): array
    {
        $secrets = [];
        try {
            foreach (null === $panel ? $this->entityManager->getRepository(VpnPanel::class)->findAll() : [$panel] as $one) {
                $secrets[] = (string) $this->credentials->apiToken($one);
                $secrets[] = (string) $this->credentials->password($one);
            }
        } catch (\Throwable) {
            // Unreadable (a key problem, the database gone): the pattern rules of SecretRedactor still apply.
        }

        return array_values(array_filter($secrets, static fn (string $s): bool => '' !== $s));
    }

    /** The fixed text of a skip reason (console, run page). Never carries a credential. */
    public static function skipText(string $reason, ?int $otherRunId = null): string
    {
        return match ($reason) {
            self::SKIP_SUSPENDED => 'suspended: out of scope (--include-suspended)',
            self::SKIP_EXPIRED => 'expired: out of scope (--include-expired)',
            self::SKIP_TRIAL => 'trial service: out of scope (--include-trials)',
            self::SKIP_DELETED => 'deleted',
            self::SKIP_NO_PANEL => 'the service has no panel',
            self::SKIP_OTHER_PANEL => ServiceInboundAttacher::OTHER_PANEL,
            self::SKIP_NOT_MODERN => 'the panel is not in modern mode (Panels → test connection)',
            self::SKIP_NOT_REAL_PANEL => 'not a real panel',
            self::SKIP_REMOTE_ID => 'the service\'s remote id cannot be rewritten (not the structured form)',
            self::SKIP_IN_OTHER_RUN => null === $otherRunId ? 'in another run' : sprintf('in run #%d', $otherRunId),
            self::SKIP_MOVED_TOO_OFTEN => sprintf('moved too often (%d moves within %d hours)', self::MAX_MOVES_PER_WINDOW, self::MOVE_WINDOW_HOURS),
            self::SKIP_PROTOCOL_MIX => 'protocol mix: the service\'s inbounds would break the plan rules (one Shadowsocks method; vision only with flow-capable inbounds)',
            self::SKIP_ALREADY_ON_TARGET => 'already on the target inbound',
            self::SKIP_INBOUND_CHANGED => 'its inbound changed since the run was created',
            self::SKIP_TARGET_GONE => 'the target inbound was deleted',
            self::SKIP_TARGET_INACTIVE => 'the target inbound is inactive',
            default => $reason,
        };
    }

    /**
     * Why the run cannot be created for $plan, or null: the plan needs an active primary inbound (4.4: "B inactive
     * refuses run creation").
     */
    public function targetProblem(Plan $plan): ?string
    {
        $target = $plan->getInbound();

        return match (true) {
            null === $target => sprintf('Plan #%d has no primary inbound: nothing to move to.', (int) $plan->getId()),
            !$target->isActive() => sprintf('The plan\'s inbound "%s" (#%d) is inactive: no run is created for an inactive target.', mb_substr($target->getTitle(), 0, 60), (int) $target->getId()),
            default => null,
        };
    }

    /** The plan of a service (through its order), or null. */
    public function planIdOf(int $serviceId): ?int
    {
        $planId = $this->connection()->fetchOne('SELECT o.plan_id FROM vpn_service s JOIN `order` o ON o.id = s.order_id WHERE s.id = ?', [$serviceId]);

        return false === $planId || null === $planId ? null : (int) $planId;
    }

    /**
     * The services of $plan not on $target, in order of id, each with "move" or why not.
     *
     * @param bool                                          $readPanel dry run: GET each movable service's client (budgeted, read only)
     * @param \Closure(InboundMoveCandidate): void|null    $onEach    called per service (console progress)
     * @param bool                                          $localOnly no panel call at all (the web dry-run page, PR P7): the
     *                                                                 protocol rules that need inbounds/options are not checked
     *                                                                 (a note says so); the run's creation checks them
     *
     * @return list<InboundMoveCandidate>
     */
    public function candidates(Plan $plan, VpnInbound $target, InboundMoveScope $scope, bool $readPanel = false, ?\Closure $onEach = null, bool $localOnly = false): array
    {
        $readPanel = $readPanel && !$localOnly;
        $candidates = [];
        $readFailures = 0;
        foreach ($this->serviceRows($plan, $target, $scope) as $row) {
            $candidate = new InboundMoveCandidate(
                (int) $row['id'],
                (string) $row['status'],
                null === $row['inbound_id'] ? null : (int) $row['inbound_id'],
                self::remoteInboundOf($row),
                null === $row['inbound_title'] ? '(deleted inbound)' : mb_substr((string) $row['inbound_title'], 0, 60),
                InboundMoveCandidate::ACTION_MOVE,
            );
            [$reason, $otherRun] = $this->skipReason($row, $target, $scope, null);
            if (null === $reason) {
                $mix = $this->protocolMix((int) $row['id'], $plan, $target, self::fromInboundOf($row), !$localOnly);
                if (true === $mix) {
                    $reason = self::SKIP_PROTOCOL_MIX;
                } elseif (null === $mix) {
                    $candidate = $candidate->withPanelNote('protocol rules not checked: inbounds/options could not be read');
                }
            }
            if (null !== $reason) {
                $candidate = $candidate->skipped($reason, self::skipText($reason, $otherRun));
            } elseif ($readPanel) {
                if ($readFailures >= self::MAX_READ_FAILURES_IN_A_ROW) {
                    $candidate = $candidate->withPanelNote(sprintf('panel not read (stopped after %d failed reads in a row)', self::MAX_READ_FAILURES_IN_A_ROW));
                } else {
                    [$note, $failed] = $this->panelNote($row, $target);
                    $readFailures = $failed ? $readFailures + 1 : 0;
                    $candidate = $candidate->withPanelNote($note);
                }
            }
            $candidates[] = $candidate;
            if (null !== $onEach) {
                $onEach($candidate);
            }
        }

        return $candidates;
    }

    /**
     * Creates the run (kind=primary, keep-old: P4 never detaches) with one move row per listed service, planned or
     * skipped, in one transaction. Refused while the switch is off, without an active target, or while the plan has an
     * open run (the database's unique key on open_plan_id, 5.1).
     *
     * @return array{int, list<InboundMoveCandidate>}|string the run id and its services, or why it was not created
     */
    public function create(Plan $plan, InboundMoveScope $scope, ?int $requestedBy = null, bool $keepOld = false, ?int $graceHours = null): array|string
    {
        if (null !== $graceHours && ($graceHours < InboundMoveSettings::MIN_DETACH_GRACE_HOURS || $graceHours > InboundMoveSettings::MAX_DETACH_GRACE_HOURS)) {
            return sprintf('The grace period must be from %d to %d hours.', InboundMoveSettings::MIN_DETACH_GRACE_HOURS, InboundMoveSettings::MAX_DETACH_GRACE_HOURS);
        }
        if (!$this->settings->enabled()) {
            return sprintf('Moving services is switched off (%s). Turn it on in Settings → Plans to use --apply; a dry run works without it.', InboundMoveSettings::ENABLED);
        }
        $problem = $this->targetProblem($plan);
        if (null !== $problem) {
            return $problem;
        }
        $open = $this->openRunId((int) $plan->getId());
        if (null !== $open) {
            return self::openRunRefusal($open);
        }
        $target = $plan->getInbound();
        \assert($target instanceof VpnInbound);
        // Read outside the transaction: the protocol rules may read the panel (GET, budgeted).
        $candidates = $this->candidates($plan, $target, $scope);

        $db = $this->connection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $db->insert('inbound_move_run', [
                'plan_id' => $plan->getId(),
                'open_plan_id' => $plan->getId(),
                'kind' => InboundMoveRun::KIND_PRIMARY,
                'target_inbound_id' => $target->getId(),
                'scope' => (string) json_encode($scope->toArray()),
                'status' => InboundMoveRun::STATUS_PLANNED,
                // P6: phase 2 removes the old inbound grace_hours after the switch; keep_old: never automatically (4.2 (c)).
                'grace_hours' => $graceHours ?? $this->settings->detachGraceHours(),
                'keep_old' => $keepOld ? 1 : 0,
                'requested_by' => $requestedBy,
                'created_at' => $now,
                'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0,
            ]);
            $runId = (int) $db->lastInsertId();
            foreach ($candidates as $candidate) {
                $move = InboundMoveCandidate::ACTION_MOVE === $candidate->action;
                $db->insert('service_inbound_move', [
                    'run_id' => $runId,
                    'service_id' => $candidate->serviceId,
                    'from_inbound_id' => $candidate->fromInboundId,
                    'from_remote_inbound_id' => $candidate->fromRemoteInboundId,
                    'to_inbound_id' => $target->getId(),
                    'to_remote_inbound_id' => $target->getRemoteInboundId(),
                    'state' => $move ? ServiceInboundMove::STATE_PLANNED : ServiceInboundMove::STATE_SKIPPED,
                    'skip_reason' => $move ? null : $candidate->skipReason,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $db->commit();
        } catch (UniqueConstraintViolationException) {
            $db->rollBack();

            return self::openRunRefusal($this->openRunId((int) $plan->getId()));
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        $moving = count(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_MOVE === $c->action));
        $this->refreshCounts($runId);
        $this->auditLogger->record('plan.inbounds_apply_started', 'plan', (int) $plan->getId(), [
            'run_id' => $runId,
            'kind' => InboundMoveRun::KIND_PRIMARY,
            'target_inbound_id' => $target->getId(),
            'services' => count($candidates),
            'planned' => $moving,
            'skipped' => count($candidates) - $moving,
            'keep_old' => $keepOld,
            'grace_hours' => $graceHours ?? $this->settings->detachGraceHours(),
        ]);
        $this->logger->info('inbound_move_run_created', ['run_id' => $runId, 'plan_id' => $plan->getId(), 'planned' => $moving, 'skipped' => count($candidates) - $moving]);

        return [$runId, $candidates];
    }

    public function openRunId(int $planId): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM inbound_move_run WHERE open_plan_id = ?', [$planId]);

        return false === $id || null === $id ? null : (int) $id;
    }

    public static function openRunRefusal(?int $runId): string
    {
        return null === $runId
            ? 'This plan already has an open run.'
            : sprintf('This plan already has an open run (#%d). Continue it with --resume=%d; only one run per plan is open at a time.', $runId, $runId);
    }

    /**
     * The cached counters of a run (5.1), by an autocommit UPDATE (never while a service row is locked, 5.3):
     * planned = every move row; done = moved (switched, detaching or done: phase 1 finished); failed; skipped;
     * remaining = planned or attaching. Undone and cancelled moves count only in planned. A remove has no phase 1: its
     * detaching move is still to come, so done = done and remaining includes detaching
     * (docs/design/plan-inbound-add-remove.md 3.3).
     */
    public function refreshCounts(int $runId): void
    {
        $by = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT state, COUNT(*) AS n FROM service_inbound_move WHERE run_id = ? GROUP BY state', [$runId]) as $row) {
            $by[(string) $row['state']] = (int) $row['n'];
        }
        $detaching = $by[ServiceInboundMove::STATE_DETACHING] ?? 0;
        $remove = InboundMoveRun::KIND_REMOVE === $this->connection()->fetchOne('SELECT kind FROM inbound_move_run WHERE id = ?', [$runId]);
        $this->connection()->executeStatement(
            'UPDATE inbound_move_run SET planned_count = ?, done_count = ?, failed_count = ?, skipped_count = ?, remaining_count = ? WHERE id = ?',
            [
                array_sum($by),
                ($by[ServiceInboundMove::STATE_SWITCHED] ?? 0) + ($remove ? 0 : $detaching) + ($by[ServiceInboundMove::STATE_DONE] ?? 0),
                $by[ServiceInboundMove::STATE_FAILED] ?? 0,
                $by[ServiceInboundMove::STATE_SKIPPED] ?? 0,
                ($by[ServiceInboundMove::STATE_PLANNED] ?? 0) + ($by[ServiceInboundMove::STATE_ATTACHING] ?? 0) + ($remove ? $detaching : 0),
                $runId,
            ],
        );
    }

    /**
     * The rules of 6.2 / 4.4 that need no panel, on a service row as read (or as locked by the runner). The runner adds
     * its own: the inbound must still be the move's A, the target must exist and be active.
     *
     * @param array<string, mixed> $service id, status, inbound_id, panel_id, remote_id, order_type
     *
     * @return array{string|null, int|null} the skip reason (null: may be moved), and the other run's id for SKIP_IN_OTHER_RUN
     */
    public function skipReason(array $service, VpnInbound $target, InboundMoveScope $scope, ?int $ownRunId): array
    {
        $status = (string) $service['status'];
        if (VpnServiceStatus::DELETED === $status) {
            return [self::SKIP_DELETED, null];
        }
        if (!$scope->statusInScope($status)) {
            return [VpnServiceStatus::SUSPENDED === $status ? self::SKIP_SUSPENDED : self::SKIP_EXPIRED, null];
        }
        if (OrderType::TRIAL === ($service['order_type'] ?? null) && !$scope->includeTrials) {
            return [self::SKIP_TRIAL, null];
        }
        $panelId = null === $service['panel_id'] ? null : (int) $service['panel_id'];
        $panel = null === $panelId ? null : $this->entityManager->find(VpnPanel::class, $panelId);
        if (!$panel instanceof VpnPanel) {
            return [self::SKIP_NO_PANEL, null];
        }
        try {
            $driver = $this->drivers->resolve($panel);
        } catch (\RuntimeException) {
            return [self::SKIP_NOT_MODERN, null];
        }
        if ($driver instanceof DummyVpnPanelDriver) {
            return [self::SKIP_NOT_REAL_PANEL, null];
        }
        if ($panelId !== (int) $target->getPanel()->getId()) {
            return [self::SKIP_OTHER_PANEL, null];
        }
        if (!$driver instanceof InboundMoveDriverInterface || !$driver instanceof InboundAttachDriverInterface) {
            return [self::SKIP_NOT_MODERN, null];
        }
        if (null === $this->remoteIdParser->withInbound((string) $service['remote_id'], (int) $target->getId(), $target->getRemoteInboundId())) {
            return [self::SKIP_REMOTE_ID, null];
        }
        $serviceId = (int) $service['id'];
        $other = $this->connection()->fetchOne(
            'SELECT m.run_id FROM service_inbound_move m WHERE m.service_id = ? AND m.run_id <> ? AND '.self::UNFINISHED_SQL.' ORDER BY m.run_id LIMIT 1',
            [$serviceId, $ownRunId ?? 0],
        );
        if (false !== $other && null !== $other) {
            return [self::SKIP_IN_OTHER_RUN, (int) $other];
        }
        $moves = (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM service_inbound_move m WHERE m.service_id = ? AND m.state IN ('switched', 'detaching', 'done', 'undone') AND m.updated_at >= ?",
            [$serviceId, (new \DateTimeImmutable(sprintf('-%d hours', self::MOVE_WINDOW_HOURS)))->format('Y-m-d H:i:s')],
        );
        if ($moves >= self::MAX_MOVES_PER_WINDOW) {
            return [self::SKIP_MOVED_TOO_OFTEN, null];
        }

        return [null, null];
    }

    /**
     * The 4.4 "protocol mix" check of the service's inbounds after the switch: B, its additional rows (not detached;
     * B's own row goes) and A when A stays one of the plan's additional inbounds. A single inbound is always fine. With
     * several, protocolRulesBroken(). Never call it inside a transaction.
     *
     * @param bool $readPanel false: never read the panel; null when the answer would need it (the web dry run)
     *
     * @return bool|null true: the mix breaks a rule (skip); false: fine; null: the panel could not be read
     */
    public function protocolMix(int $serviceId, Plan $plan, VpnInbound $target, ?int $fromInboundId, bool $readPanel = true): ?bool
    {
        $ids = array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT inbound_id FROM vpn_service_inbound WHERE service_id = ? AND status <> ? ORDER BY id',
            [$serviceId, VpnServiceInbound::STATUS_DETACHED],
        ));
        if (null !== $fromInboundId && in_array($fromInboundId, $this->planAdditionalIds((int) $plan->getId()), true)) {
            $ids[] = $fromInboundId;
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id !== (int) $target->getId())));
        if ([] === $ids) {
            return false;
        }
        $additional = [];
        foreach ($ids as $id) {
            $inbound = $this->entityManager->find(VpnInbound::class, $id);
            if ($inbound instanceof VpnInbound) {
                $additional[] = $inbound;
            }
        }

        return $this->protocolRulesBroken($target, $additional, $readPanel);
    }

    /**
     * The D1 rules of PlanInboundsValidator for a primary inbound and its additional inbounds: known protocols only; one
     * Shadowsocks method; a VLESS vision primary only with flow-capable VLESS additional inbounds. The last two read the
     * panel's inbounds/options once per panel (GET, budgeted, recordHealth=false), only when they matter. Never call it
     * inside a transaction.
     *
     * @param list<VpnInbound> $additional
     * @param bool             $readPanel  false: never read the panel; null when the answer would need it
     *
     * @return bool|null true: the mix breaks a rule; false: fine; null: the panel could not be read
     */
    public function protocolRulesBroken(VpnInbound $primary, array $additional, bool $readPanel = true): ?bool
    {
        $all = [$primary, ...$additional];
        foreach ($all as $inbound) {
            if (!in_array(PlanInboundsValidator::protocol($inbound), PlanInboundsValidator::PROTOCOLS, true)) {
                return true;
            }
        }
        $shadowsocks = array_values(array_filter($all, static fn (VpnInbound $i): bool => 'shadowsocks' === PlanInboundsValidator::protocol($i)));
        $vision = 'vless' === PlanInboundsValidator::protocol($primary) && str_contains(strtolower((string) $primary->getFlow()), 'vision');
        $vless = array_values(array_filter($additional, static fn (VpnInbound $i): bool => 'vless' === PlanInboundsValidator::protocol($i)));
        if (count($shadowsocks) < 2 && (!$vision || [] === $vless)) {
            return false;
        }
        $options = $readPanel ? $this->panelOptions($primary->getPanel()) : null;
        if (null === $options) {
            return null;
        }
        if (count($shadowsocks) >= 2) {
            $methods = array_map(static fn (VpnInbound $i): string => strtolower(trim((string) ($options[$i->getRemoteInboundId()]['ssMethod'] ?? ''))), $shadowsocks);
            if (in_array('', $methods, true) || count(array_unique($methods)) > 1) {
                return true;
            }
        }
        if ($vision) {
            foreach ($vless as $inbound) {
                if (true !== ($options[$inbound->getRemoteInboundId()]['tlsFlowCapable'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Forgets the inbounds/options read so far (each runner pass reads them again). */
    public function forgetPanelReads(): void
    {
        $this->options = [];
    }

    /** @return list<int> */
    public function planAdditionalIds(int $planId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn('SELECT inbound_id FROM plan_additional_inbound WHERE plan_id = ?', [$planId]));
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function panelOptions(VpnPanel $panel): ?array
    {
        $key = (int) $panel->getId();
        if (!array_key_exists($key, $this->options)) {
            if ($this->connection()->isTransactionActive()) {
                // Never a panel call under someone's locks (5.3).
                return null;
            }
            try {
                $rows = $this->budget->within(self::STEP_BUDGET_SECONDS, fn (): array => $this->api->inboundOptions($panel, false));
                $byId = [];
                foreach ($rows as $row) {
                    $byId[(string) $row['id']] = $row;
                }
                $this->options[$key] = $byId;
            } catch (\Throwable $e) {
                $this->logger->warning('inbound_move_options_unreadable', ['panel_id' => $key, 'error' => SafeText::of($e->getMessage(), '', 255, $this->panelSecrets($panel))]);
                $this->options[$key] = null;
            }
        }

        return $this->options[$key];
    }

    /**
     * Dry run: what the panel says about the client (one GET, budgeted, read only), as a short text.
     *
     * @param array<string, mixed> $row
     *
     * @return array{string, bool} the note, and whether the panel could not be read
     */
    private function panelNote(array $row, VpnInbound $target): array
    {
        if ($this->connection()->isTransactionActive()) {
            return ['panel not read (a transaction is open)', false];
        }
        $panel = $this->entityManager->find(VpnPanel::class, (int) $row['panel_id']);
        $driver = $panel instanceof VpnPanel ? $this->drivers->resolve($panel) : null;
        if (!$driver instanceof InboundAttachDriverInterface) {
            return ['panel not read', false];
        }
        try {
            $ids = $this->budget->within(self::STEP_BUDGET_SECONDS, fn (): ?array => $driver->clientInboundIds((string) $row['remote_id'], $panel, false));
        } catch (\Throwable $e) {
            $secrets = $this->panelSecrets($panel);
            $this->logger->warning('inbound_move_dry_run_read_failed', ['service_id' => (int) $row['id'], 'error' => SafeText::of($e->getMessage(), (string) ($row['client_email'] ?? ''), 255, $secrets)]);

            return ['panel not read: '.SafeText::of($e->getMessage(), (string) ($row['client_email'] ?? ''), 120, $secrets), true];
        }
        if (null === $ids) {
            return ['no such client on the panel: would fail', false];
        }
        $to = (int) $target->getRemoteInboundId();
        $from = (int) (self::remoteInboundOf($row) ?? 0);

        return match (true) {
            in_array($to, $ids, true) => ['already on the target on the panel: no attach needed', false],
            in_array($from, $ids, true) => ['on the old inbound: would attach the target', false],
            default => ['on neither the old nor the target inbound: would fail, nothing sent', false],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serviceRows(Plan $plan, VpnInbound $target, InboundMoveScope $scope): array
    {
        $where = ['o.plan_id = :plan', 's.status <> :deleted', '(s.inbound_id IS NULL OR s.inbound_id <> :target)', 's.id > :after'];
        $params = ['plan' => $plan->getId(), 'deleted' => VpnServiceStatus::DELETED, 'target' => $target->getId(), 'after' => $scope->afterId];
        if (null !== $scope->serviceId) {
            $where[] = 's.id = :service';
            $params['service'] = $scope->serviceId;
        }
        if (null !== $scope->fromInboundId) {
            $where[] = 's.inbound_id = :from';
            $params['from'] = $scope->fromInboundId;
        }

        return $this->connection()->fetchAllAssociative(
            sprintf(
                'SELECT s.id, s.status, s.inbound_id, s.panel_id, s.remote_id, s.client_email, o.type AS order_type, i.title AS inbound_title, i.remote_inbound_id AS inbound_remote_id
                 FROM vpn_service s JOIN `order` o ON o.id = s.order_id LEFT JOIN vpn_inbound i ON i.id = s.inbound_id
                 WHERE %s ORDER BY s.id%s',
                implode(' AND ', $where),
                null === $scope->limit ? '' : ' LIMIT '.max(1, $scope->limit),
            ),
            $params,
        );
    }

    /**
     * A's panel id: the local inbound's, else the one the remote id records (A deleted locally, 4.4).
     *
     * @param array<string, mixed> $row
     */
    public static function remoteInboundOf(array $row): ?string
    {
        $remote = trim((string) ($row['inbound_remote_id'] ?? ''));
        if ('' !== $remote) {
            return $remote;
        }
        $ref = (new Sanaei3xuiRemoteIdParser())->parse((string) ($row['remote_id'] ?? ''));

        return null === $ref ? null : trim($ref->inboundId);
    }

    /** @param array<string, mixed> $row */
    private static function fromInboundOf(array $row): ?int
    {
        return null === $row['inbound_id'] ? null : (int) $row['inbound_id'];
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
