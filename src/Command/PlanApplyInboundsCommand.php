<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\InboundMoveRun;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Provisioning\Application\InboundMoveCandidate;
use App\Provisioning\Application\InboundMoveControls;
use App\Provisioning\Application\InboundMoveHeldLock;
use App\Provisioning\Application\InboundMoveLock;
use App\Provisioning\Application\InboundMoveItem;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Application\SafeText;
use App\Shop\Application\InboundMoveSettings;
use App\Shop\Application\MultiInboundSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves a plan's existing services to the plan's current primary inbound (docs/design/plan-inbound-change.md 4.3,
 * 5.2, 5.4, 6.2; PR P4 phase 1, PR P6 phase 2 and the run controls): attach the new inbound, switch the service to it,
 * refresh its links; after the grace period remove the old inbound. With --kind=add (PR P11b, docs/design/
 * plan-inbound-add-remove.md) it puts them on one of the plan's additional inbounds instead, and with --kind=remove
 * (PR P11c) it takes an inbound the plan no longer sells off them. Dry run unless --apply. See the help text.
 *
 * Exit codes: 0 every service moved or skipped; 1 some failed (or the run paused, or an undo is incomplete); 2 could
 * not start.
 */
#[AsCommand(name: 'app:plan:apply-inbounds', description: 'Move a plan\'s existing services to its current inbound (attach, switch, refresh links; the old inbound removed after a grace period), add one of its additional inbounds to them (--kind=add), or take an inbound it no longer sells off them (--kind=remove); dry run unless --apply')]
final class PlanApplyInboundsCommand extends Command
{
    private const EXIT_FAILED = 1;
    private const EXIT_NOT_STARTED = 2;

    /** Held for the whole of a pass; --pause-run and --cancel never take it, they must work during one. */
    private ?InboundMoveHeldLock $held = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMovePlanner $planner,
        private readonly InboundMoveRunner $runner,
        private readonly InboundMoveControls $controls,
        private readonly InboundMoveSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly InboundMoveLock $lock,
        private readonly MultiInboundSettings $multiInbound,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('planId', InputArgument::REQUIRED, 'The plan whose existing services are moved to its current primary inbound')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'primary: move to the plan\'s primary inbound; add: put the services on --inbound as well; remove: take --inbound off them', InboundMoveRun::KIND_PRIMARY)
            ->addOption('inbound', null, InputOption::VALUE_REQUIRED, 'With --kind=add: the inbound to add (one of the plan\'s additional inbounds). With --kind=remove: the inbound to remove (no longer in the plan)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Create the run and move the services (without it: a dry run, nothing is written)')
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Only this service (the recommended first test)')
            ->addOption('from-inbound', null, InputOption::VALUE_REQUIRED, 'Only services now on this (old) inbound id')
            ->addOption('include-suspended', null, InputOption::VALUE_NONE, 'Also suspended services (default: active only)')
            ->addOption('include-expired', null, InputOption::VALUE_NONE, 'Also expired services')
            ->addOption('include-trials', null, InputOption::VALUE_NONE, 'Also trial services')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, sprintf('Services per batch (%d-%d; default: the setting %s)', InboundMoveSettings::MIN_BATCH_SIZE, InboundMoveSettings::MAX_BATCH_SIZE, InboundMoveSettings::BATCH_SIZE))
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, sprintf('Seconds between batches (%d-%d; default: the setting %s)', InboundMoveSettings::MIN_PAUSE_SECONDS, InboundMoveSettings::MAX_PAUSE_SECONDS, InboundMoveSettings::PAUSE_SECONDS))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many services (in order of id)')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Only services with a higher id (to continue a dry run or a partial pass)')
            ->addOption('resume', null, InputOption::VALUE_REQUIRED, 'Continue this open run of the plan (paused, interrupted, or with old inbounds due for removal); it writes, like --apply')
            ->addOption('keep-old', null, InputOption::VALUE_NONE, 'With --apply: never remove the old inbound automatically (only --retire-now does)')
            ->addOption('grace-hours', null, InputOption::VALUE_REQUIRED, sprintf('With --apply: hours between the switch and the removal of the old inbound (%d-%d; default: the setting %s). With --kind=remove: hours before the inbound is removed (default: 0)', InboundMoveSettings::MIN_DETACH_GRACE_HOURS, InboundMoveSettings::MAX_DETACH_GRACE_HOURS, InboundMoveSettings::DETACH_GRACE_HOURS))
            ->addOption('retire-now', null, InputOption::VALUE_OPTIONAL, 'Make the removal of the old inbound due now for the moved services of this run (default: the plan\'s latest run); with --service only that one. Add --resume=<runId> to remove them in the same command', false)
            ->addOption('pause-run', null, InputOption::VALUE_REQUIRED, 'Pause this run: a pass working on it finishes the service in hand and takes no other')
            ->addOption('cancel', null, InputOption::VALUE_REQUIRED, 'Cancel this run: services not moved yet will not be moved; services in the middle of a step finish it')
            ->addOption('undo', null, InputOption::VALUE_REQUIRED, 'Put the moved services of this run back on their old inbound (only those whose old inbound was not removed yet)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
                Moves the existing services of a plan to the plan's CURRENT primary inbound, on modern 3x-ui panels,
                in two phases.

                Phase 1, "switch" (per service):
                  1. the client is attached to the new inbound as well (nothing is sent when it already is);
                  2. the service is switched to the new inbound in the bot (inbound, remote id);
                  3. its links are refreshed from the panel (subLinks: the old and the new inbound).
                Phase 2, "retire", after the GRACE PERIOD (default 24 hours, setting
                plans.inbound_move_detach_grace_hours, or --grace-hours=N at --apply, 0-168):
                  4. the old inbound is removed from the client (never the client's only inbound: if the new one is
                     missing on the panel it is attached again first), then the links are refreshed (new inbound only).
                The customer's UUID, email, subscription id and URL, expiry, limits and traffic are not changed. During
                the grace period a customer whose app has not fetched the subscription yet keeps working on the old
                inbound. --keep-old at --apply: the old inbound is never removed automatically (--retire-now only).

                How the removals happen: the scheduled task inbound-moves (app:automation:run --only=inbound-moves, every
                30 minutes) continues every planned or running run: what is left of phase 1, retries, and every removal
                that is due. It never touches a paused run. Running the command again with --resume=<runId> does the
                same at once, in one pass (running it twice does nothing twice). To remove the old inbound without
                waiting: --retire-now (the plan's latest run, or --retire-now=<runId>; --service=<id> for one service),
                then --resume=<runId>, or both in one command: --retire-now=<runId> --resume=<runId>.

                Without --apply it is a DRY RUN: it writes nothing and only reads the panel (one GET per service,
                time-limited) to show who is already on the new inbound. It works with the switch off.

                Recommended order:
                  php bin/console app:plan:apply-inbounds <plan>                        (dry run, all services)
                  php bin/console app:plan:apply-inbounds <plan> --service=<id> --apply (ONE service first)
                  check that service (panel, links, the customer's app), then
                  php bin/console app:plan:apply-inbounds <plan> --apply                (the rest)
                  after the grace period: the scheduled task, or php bin/console app:plan:apply-inbounds <plan> --resume=<runId>

                Only one pass runs at a time: --apply, --resume, --retire-now and --undo take the same lock as the
                scheduled task and refuse at once ("a scheduled run is in progress, try again in a minute"). --pause-run,
                --cancel and the dry run do not need it, so a pass can be paused while it works.

                Run controls (each takes the run id the output names):
                  --pause-run=<runId>  pause: a pass working on the run finishes the service in hand, takes no other;
                  --resume=<runId>     continue (also after a pause the run made itself: 5 failed panel reads);
                  --cancel=<runId>     services not moved yet will not be moved; a service in the middle of a step
                                       finishes it; moved services still wait for their old inbound to be removed;
                  --undo=<runId>       moved services whose old inbound is STILL attached go back to it (the new
                                       inbound is removed unless it is one of the plan's additional inbounds); the
                                       services not moved yet are cancelled. Undo cannot put back a service whose old
                                       inbound was already removed: that needs a new run from the new inbound back to
                                       the old one (it says how many services that concerns).

                --apply needs plans.inbound_move_enabled on (Settings -> Plans). One run per plan is open at a time;
                a second --apply is refused and names it. Failed services are retried with the attach retry settings
                (plans.attach_max_attempts, plans.attach_retry_delay_minutes) by the scheduled task or the next --resume.

                Scope: active services only unless --include-suspended / --include-expired / --include-trials;
                deleted ones never. Skipped, with the reason: services on another panel, on a legacy (not modern)
                panel or the dummy driver, in another unfinished run, moved 3 times within 24 hours, or whose
                inbounds would break the plan's protocol rules.

                Adding an inbound (--kind=add --inbound=<id>): a plan that sells a new additional inbound gives it
                to new sales only; this puts the existing services on it as well, in one go. The inbound must be one of
                the plan's additional inbounds, active, on the plan's panel; plans.multi_inbound_enabled must be on as
                well. The client is attached to the inbound, the service gets its additional row, and its links are
                refreshed from the panel. The primary inbound, UUID, subscription, expiry, limits and traffic stay as
                they are, and the stored links stay visible meanwhile. A dry run shows what each client is on now and
                after. --resume, --pause-run, --cancel and the scheduled task work as for a move (--retire-now, --keep-old,
                --grace-hours and --include-trials do not apply). If the plan stops selling the inbound the run pauses:
                put it back and --resume, or --cancel. --undo takes the inbound off the services this run added it to
                (not those that had it before, and only for the plan's latest run), and leaves the plan as it is.

                Removing an inbound (--kind=remove --inbound=<id>): change the plan FIRST, so that it no longer sells the
                inbound (not its primary and not an additional one: to change a primary, use a move). New sales then
                leave it out, and this takes it off the existing services. plans.multi_inbound_enabled must be on as well.
                The service's row leaves "attached" before the panel is called, so the attach timer never puts the
                client back; then the inbound is detached, the row is deleted, and the links are refreshed from the
                panel (the stored links are hidden until that succeeds). A service never loses its primary or its
                client's last inbound: those are skipped, and keep the inbound. The primary, UUID, subscription, expiry,
                limits and traffic stay as they are. A dry run shows what each client is on now and after.
                --grace-hours=N (0-168, default 0: the setting of a move is not read) waits before the removal: a window to
                --cancel. --retire-now=<runId> makes the removal due now, and tries a failed or given-up service again.
                --resume, --pause-run, --cancel and the scheduled task work as for a move; --cancel leaves a service that
                was started alone (a failed one stays held until --retire-now or --undo). If the plan sells the inbound
                again the run pauses: take it off and --resume, or --cancel, or --undo. --undo puts the inbound back,
                also for a finished service (only for the plan's latest run), and leaves the plan as it is.
                --from-inbound, --keep-old and --include-trials do not apply.

                The output lists service ids and inbound titles only: never links, UUIDs, tokens or customers.
                Exit codes: 0 done (moved or skipped), 1 some failed, the run paused or an undo is incomplete,
                2 could not start.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        try {
            return $this->runCommand($input, $io, $json, $output);
        } catch (\Throwable $e) {
            $text = SafeText::of($e->getMessage(), '', 255, $this->planner->panelSecrets());
            $this->logger->error('inbound_move_command_failed', ['error' => $text, 'class' => $e::class]);
            $this->fail($io, $output, $json, 'Stopped: '.$text, self::EXIT_FAILED);

            return self::EXIT_FAILED;
        } finally {
            $this->held?->release();
            $this->held = null;
        }
    }

    private function runCommand(InputInterface $input, SymfonyStyle $io, bool $json, OutputInterface $output): int
    {
        $ints = [];
        $retireNow = $input->getOption('retire-now');
        $zeroAllowed = ['pause', 'after-id', 'grace-hours'];
        foreach (['planId' => (string) $input->getArgument('planId'), 'service' => $input->getOption('service'), 'from-inbound' => $input->getOption('from-inbound'), 'batch-size' => $input->getOption('batch-size'), 'pause' => $input->getOption('pause'), 'limit' => $input->getOption('limit'), 'after-id' => $input->getOption('after-id'), 'resume' => $input->getOption('resume'), 'grace-hours' => $input->getOption('grace-hours'), 'inbound' => $input->getOption('inbound'), 'pause-run' => $input->getOption('pause-run'), 'cancel' => $input->getOption('cancel'), 'undo' => $input->getOption('undo'), 'retire-now' => is_string($retireNow) ? $retireNow : null] as $name => $value) {
            if (null === $value) {
                $ints[$name] = null;
                continue;
            }
            if (1 !== preg_match('/^\d{1,9}$/D', (string) $value) || (!in_array($name, $zeroAllowed, true) && 0 === (int) $value)) {
                return $this->fail($io, $output, $json, sprintf('--%s must be a whole number%s.', $name, in_array($name, $zeroAllowed, true) ? '' : ' above 0'));
            }
            $ints[$name] = (int) $value;
        }
        if (null !== $ints['grace-hours'] && ($ints['grace-hours'] < InboundMoveSettings::MIN_DETACH_GRACE_HOURS || $ints['grace-hours'] > InboundMoveSettings::MAX_DETACH_GRACE_HOURS)) {
            return $this->fail($io, $output, $json, sprintf('--grace-hours must be from %d to %d.', InboundMoveSettings::MIN_DETACH_GRACE_HOURS, InboundMoveSettings::MAX_DETACH_GRACE_HOURS));
        }
        $retire = false !== $retireNow;
        $kind = (string) $input->getOption('kind');
        if (!in_array($kind, InboundMoveRun::RUNNABLE_KINDS, true)) {
            return $this->fail($io, $output, $json, '--kind must be primary, add or remove.');
        }
        $add = InboundMoveRun::KIND_ADD === $kind;
        $remove = InboundMoveRun::KIND_REMOVE === $kind;
        if (!$add && !$remove && null !== $ints['inbound']) {
            return $this->fail($io, $output, $json, '--inbound goes with --kind=add or --kind=remove only: a move always goes to the plan\'s primary inbound.');
        }
        if ($remove) {
            if (null === $ints['inbound']) {
                return $this->fail($io, $output, $json, '--kind=remove needs --inbound=<id>: the inbound to remove.');
            }
            $given = array_merge(
                array_filter(['keep-old', 'include-trials'], static fn (string $o): bool => (bool) $input->getOption($o)),
                array_filter(['from-inbound'], static fn (string $o): bool => null !== $ints[$o]),
            );
            if ([] !== $given) {
                return $this->fail($io, $output, $json, sprintf('--%s does not apply to --kind=remove: it takes the services with a row for the inbound, and a trial\'s backing plan takes no remove.', implode(', --', $given)));
            }
            $named = array_filter(['resume', 'pause-run', 'cancel', 'undo'], static fn (string $o): bool => null !== $ints[$o]);
            if ($retire || [] !== $named) {
                return $this->fail($io, $output, $json, sprintf('A run keeps the kind and inbound it was created with: --%s takes neither --kind nor --inbound.', implode(', --', [...($retire ? ['retire-now'] : []), ...$named])));
            }
        }
        if ($add) {
            if (null === $ints['inbound']) {
                return $this->fail($io, $output, $json, '--kind=add needs --inbound=<id>: the inbound to add.');
            }
            $given = array_merge(
                $retire ? ['retire-now'] : [],
                array_filter(['keep-old', 'include-trials'], static fn (string $o): bool => (bool) $input->getOption($o)),
                array_filter(['grace-hours'], static fn (string $o): bool => null !== $ints[$o]),
            );
            if ([] !== $given) {
                return $this->fail($io, $output, $json, sprintf('--%s does not apply to --kind=add: nothing is removed, and a trial\'s backing plan takes no add.', implode(', --', $given)));
            }
            $named = array_filter(['resume', 'pause-run', 'cancel', 'undo'], static fn (string $o): bool => null !== $ints[$o]);
            if ([] !== $named) {
                return $this->fail($io, $output, $json, sprintf('A run keeps the kind and inbound it was created with: --%s takes neither --kind nor --inbound.', implode(', --', $named)));
            }
        }
        $controls = array_values(array_filter(['pause-run', 'cancel', 'undo'], static fn (string $o): bool => null !== $ints[$o]));
        $apply = (bool) $input->getOption('apply');
        $actions = array_merge($controls, $apply ? ['apply'] : [], $retire ? ['retire-now'] : [], null !== $ints['resume'] && !$retire ? ['resume'] : []);
        if (count($actions) > 1) {
            return $this->fail($io, $output, $json, 'Use one of --apply, --resume, --retire-now (with or without --resume), --pause-run, --cancel, --undo at a time (given: --'.implode(', --', $actions).').');
        }
        if (!$apply && ((bool) $input->getOption('keep-old') || null !== $ints['grace-hours'])) {
            return $this->fail($io, $output, $json, '--keep-old and --grace-hours are fixed when a run is created: use them with --apply.');
        }
        if ((bool) $input->getOption('keep-old') && null !== $ints['grace-hours']) {
            return $this->fail($io, $output, $json, '--keep-old never removes the old inbound automatically: --grace-hours has no meaning with it.');
        }
        if (null !== $ints['batch-size'] && ($ints['batch-size'] < InboundMoveSettings::MIN_BATCH_SIZE || $ints['batch-size'] > InboundMoveSettings::MAX_BATCH_SIZE)) {
            return $this->fail($io, $output, $json, sprintf('--batch-size must be from %d to %d.', InboundMoveSettings::MIN_BATCH_SIZE, InboundMoveSettings::MAX_BATCH_SIZE));
        }
        if (null !== $ints['pause'] && $ints['pause'] > InboundMoveSettings::MAX_PAUSE_SECONDS) {
            return $this->fail($io, $output, $json, sprintf('--pause must be from %d to %d seconds.', InboundMoveSettings::MIN_PAUSE_SECONDS, InboundMoveSettings::MAX_PAUSE_SECONDS));
        }
        $plan = $this->entityManager->find(Plan::class, (int) $ints['planId']);
        if (!$plan instanceof Plan) {
            return $this->fail($io, $output, $json, sprintf('There is no plan #%d.', (int) $ints['planId']));
        }
        $scope = new InboundMoveScope(
            $ints['service'],
            $ints['from-inbound'],
            (bool) $input->getOption('include-suspended'),
            (bool) $input->getOption('include-expired'),
            (bool) $input->getOption('include-trials'),
            $ints['limit'],
            (int) ($ints['after-id'] ?? 0),
        );
        if (null !== $scope->serviceId && $this->planner->planIdOf($scope->serviceId) !== (int) $plan->getId()) {
            return $this->fail($io, $output, $json, sprintf('Service #%d is not a service of plan #%d (services belong to the plan of their order).', $scope->serviceId, (int) $plan->getId()));
        }

        $target = null;
        if ($add || $remove) {
            $target = $this->entityManager->find(VpnInbound::class, (int) $ints['inbound']);
            if (!$target instanceof VpnInbound) {
                return $this->fail($io, $output, $json, sprintf('There is no inbound #%d.', (int) $ints['inbound']));
            }
        }

        $scoped = array_merge(array_filter(['service', 'from-inbound', 'limit', 'after-id'], static fn (string $o): bool => null !== $ints[$o]),
            array_filter(['include-suspended', 'include-expired', 'include-trials'], static fn (string $o): bool => (bool) $input->getOption($o)));
        // The same lock as the scheduled task (5.2): never two passes at once, and no waiting.
        if ($apply || $retire || null !== $ints['resume'] || null !== $ints['undo']) {
            $this->held = $this->lock->acquire();
            if (null === $this->held) {
                return $this->fail($io, $output, $json, 'Refused: a scheduled run is in progress, try again in a minute (another app:plan:apply-inbounds pass takes the same lock). Nothing was changed.');
            }
        }
        if ([] !== $controls) {
            if ([] !== $scoped) {
                return $this->fail($io, $output, $json, sprintf('--%s acts on a whole run: it takes no scope option (--%s).', $controls[0], implode(', --', $scoped)));
            }
            $runId = (int) $ints[$controls[0]];
            if (!$this->runOfPlan($runId, $plan)) {
                return $this->fail($io, $output, $json, sprintf('Run #%d is not a run of plan #%d.', $runId, (int) $plan->getId()));
            }

            return match ($controls[0]) {
                'pause-run' => $this->control($this->controls->pause($runId), $io, $output, $json),
                'cancel' => $this->control($this->controls->cancel($runId), $io, $output, $json),
                default => $this->undo($plan, $runId, $io, $output, $json),
            };
        }
        if ($retire) {
            if ([] !== array_diff($scoped, ['service'])) {
                return $this->fail($io, $output, $json, '--retire-now takes only --service as a scope option (--'.implode(', --', array_diff($scoped, ['service'])).').');
            }
            $runId = $ints['retire-now'] ?? $ints['resume'] ?? $this->latestRunId($plan);
            if (null === $runId || !$this->runOfPlan($runId, $plan)) {
                return $this->fail($io, $output, $json, null === $runId ? sprintf('Plan #%d has no run yet.', (int) $plan->getId()) : sprintf('Run #%d is not a run of plan #%d.', $runId, (int) $plan->getId()));
            }
            if (null !== $ints['resume'] && $ints['resume'] !== $runId) {
                return $this->fail($io, $output, $json, sprintf('--retire-now=%d and --resume=%d name two runs: use the same run.', $runId, $ints['resume']));
            }
            if (!$this->settings->enabled()) {
                return $this->fail($io, $output, $json, sprintf('Moving services is switched off (%s): nothing was changed.', InboundMoveSettings::ENABLED));
            }
            $result = $this->controls->retireNow($runId, $ints['service']);
            if (!$result['ok'] || null === $ints['resume']) {
                return $this->control($result + ['next' => $result['ok'] ? sprintf(InboundMoveRun::KIND_REMOVE === $this->runKind($runId) ? 'The inbound is removed by the next pass: --resume=%d' : 'The old inbounds are removed by the next pass: --resume=%d', $runId) : null], $io, $output, $json);
            }
            if (!$json) {
                $io->writeln($result['message']);
            }

            return $this->resume($plan, $runId, $ints['batch-size'], $ints['pause'], $io, $output, $json);
        }
        if (null !== $ints['resume']) {
            return [] !== $scoped
                ? $this->fail($io, $output, $json, 'A run\'s scope was frozen when it was created: --resume takes no scope option (--'.implode(', --', $scoped).').')
                : $this->resume($plan, (int) $ints['resume'], $ints['batch-size'], $ints['pause'], $io, $output, $json);
        }
        if (!$apply) {
            return match (true) {
                $remove => $this->dryRunRemove($plan, $target, $scope, $io, $output, $json),
                $target instanceof VpnInbound => $this->dryRunAdd($plan, $target, $scope, $io, $output, $json),
                default => $this->dryRun($plan, $scope, $io, $output, $json),
            };
        }
        if ($remove) {
            $grace = $ints['grace-hours'] ?? 0;
            $created = $this->planner->createRemove($plan, $target, $scope, $grace);
            if (is_string($created)) {
                return $this->fail($io, $output, $json, $created);
            }
            [$runId, $candidates] = $created;
            if (!$json) {
                $io->title(sprintf('Plan #%d "%s": removing %s from existing services (run #%d, %s)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($target), $runId, 0 === $grace ? 'at once' : sprintf('after %d hour(s)', $grace)));
                foreach ($candidates as $candidate) {
                    if (InboundMoveCandidate::ACTION_SKIP === $candidate->action) {
                        $io->writeln(sprintf('Service #%d (%s): skipped: %s', $candidate->serviceId, $candidate->status, (string) $candidate->skipText));
                    }
                }
            }

            return $this->process($plan, $runId, $ints['batch-size'], $ints['pause'], $io, $output, $json, $candidates);
        }
        if ($target instanceof VpnInbound) {
            $created = $this->planner->createAdd($plan, $target, $scope);
            if (is_string($created)) {
                return $this->fail($io, $output, $json, $created);
            }
            [$runId, $candidates] = $created;
            if (!$json) {
                $io->title(sprintf('Plan #%d "%s": adding %s to existing services (run #%d)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($target), $runId));
                foreach ($candidates as $candidate) {
                    if (InboundMoveCandidate::ACTION_SKIP === $candidate->action) {
                        $io->writeln(sprintf('Service #%d (%s, on %s): skipped: %s', $candidate->serviceId, $candidate->status, $candidate->fromTitle, (string) $candidate->skipText));
                    }
                }
            }

            return $this->process($plan, $runId, $ints['batch-size'], $ints['pause'], $io, $output, $json, $candidates);
        }

        $keepOld = (bool) $input->getOption('keep-old');
        $created = $this->planner->create($plan, $scope, null, $keepOld, $ints['grace-hours']);
        if (is_string($created)) {
            return $this->fail($io, $output, $json, $created);
        }
        [$runId, $candidates] = $created;
        if (!$json) {
            $io->title(sprintf('Plan #%d "%s": moving existing services to %s (run #%d, %s)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($plan->getInbound()), $runId,
                $keepOld ? 'the old inbound stays: --keep-old' : sprintf('the old inbound is removed %d hour(s) after the switch', $ints['grace-hours'] ?? $this->settings->detachGraceHours())));
            foreach ($candidates as $candidate) {
                if (InboundMoveCandidate::ACTION_SKIP === $candidate->action) {
                    $io->writeln(sprintf('Service #%d (%s, on %s): skipped: %s', $candidate->serviceId, $candidate->status, $candidate->fromTitle, (string) $candidate->skipText));
                }
            }
        }

        return $this->process($plan, $runId, $ints['batch-size'], $ints['pause'], $io, $output, $json, $candidates);
    }

    private function dryRun(Plan $plan, InboundMoveScope $scope, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $target = $plan->getInbound();
        if (!$target instanceof VpnInbound) {
            return $this->fail($io, $output, $json, (string) $this->planner->targetProblem($plan));
        }
        $notes = array_values(array_filter([
            $this->planner->targetProblem($plan),
            $this->settings->enabled() ? null : sprintf('The switch %s is off: --apply would be refused (this dry run works without it).', InboundMoveSettings::ENABLED),
            null === ($open = $this->planner->openRunId((int) $plan->getId())) ? null : InboundMovePlanner::openRunRefusal($open),
        ]));
        if (!$json) {
            $io->title(sprintf('Plan #%d "%s": dry run, moving existing services to %s (nothing is written)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($target)));
        }
        $candidates = $this->planner->candidates($plan, $target, $scope, true);
        $moving = array_values(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_MOVE === $c->action));
        $summary = ['services' => count($candidates), 'would_move' => count($moving), 'skipped' => count($candidates) - count($moving)];
        if ($json) {
            $output->writeln((string) json_encode([
                'plan_id' => $plan->getId(),
                'plan' => $plan->getTitle(),
                'target_inbound_id' => $target->getId(),
                'target_inbound' => $target->getTitle(),
                'dry_run' => true,
                'notes' => $notes,
                'services' => array_map(static fn (InboundMoveCandidate $c): array => $c->toArray(), $candidates),
                'summary' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        if ([] === $candidates) {
            $io->writeln('No service of this plan (in this scope) is on another inbound: nothing to move.');
        } else {
            $io->table(
                ['Service', 'Status', 'Current inbound', 'Target', 'Action', 'Reason / panel'],
                array_map(static fn (InboundMoveCandidate $c): array => [
                    '#'.$c->serviceId,
                    $c->status,
                    $c->fromTitle,
                    mb_substr($target->getTitle(), 0, 60),
                    InboundMoveCandidate::ACTION_MOVE === $c->action ? 'attach + switch' : 'skip',
                    (string) ($c->skipText ?? $c->panelNote ?? ''),
                ], $candidates),
            );
        }
        foreach ($notes as $note) {
            $io->note($note);
        }
        $io->writeln(sprintf('%d service(s): %d would be moved, %d skipped. Nothing was changed. Test ONE service first: --service=<id> --apply.', ...array_values($summary)));

        return Command::SUCCESS;
    }

    /** A dry run of --kind=add: per service, what the client is on now and after. Only GETs; nothing is written. */
    private function dryRunAdd(Plan $plan, VpnInbound $target, InboundMoveScope $scope, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $notes = array_values(array_filter([
            $this->planner->addTargetProblem($plan, $target),
            $this->settings->enabled() ? null : sprintf('The switch %s is off: --apply would be refused (this dry run works without it).', InboundMoveSettings::ENABLED),
            $this->multiInbound->enabled() ? null : sprintf('The switch %s is off: --apply would be refused (this dry run works without it).', MultiInboundSettings::ENABLED),
            null === ($open = $this->planner->openRunId((int) $plan->getId())) ? null : InboundMovePlanner::openRunRefusal($open),
        ]));
        if (!$json) {
            $io->title(sprintf('Plan #%d "%s": dry run, adding %s to existing services (nothing is written)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($target)));
        }
        $candidates = $this->planner->addCandidates($plan, $target, $scope, true);
        $adding = count(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_ADD === $c->action));
        $summary = ['services' => count($candidates), 'would_add' => $adding, 'skipped' => count($candidates) - $adding];
        if ($json) {
            $output->writeln((string) json_encode([
                'plan_id' => $plan->getId(),
                'plan' => $plan->getTitle(),
                'kind' => InboundMoveRun::KIND_ADD,
                'target_inbound_id' => $target->getId(),
                'target_inbound' => $target->getTitle(),
                'dry_run' => true,
                'notes' => $notes,
                'services' => array_map(static fn (InboundMoveCandidate $c): array => $c->toArray(), $candidates),
                'summary' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        if ([] === $candidates) {
            $io->writeln('Every service of this plan (in this scope) has this inbound already: nothing to add.');
        } else {
            $list = static fn (?array $titles): string => null === $titles ? '?' : implode(', ', $titles);
            $io->table(
                ['Service', 'Status', 'Now on panel', 'After', 'Action', 'Note'],
                array_map(static fn (InboundMoveCandidate $c): array => [
                    '#'.$c->serviceId,
                    $c->status,
                    $list($c->inboundsNow),
                    $list($c->inboundsAfter),
                    InboundMoveCandidate::ACTION_ADD === $c->action ? 'add' : 'skip',
                    (string) ($c->skipText ?? $c->panelNote ?? ''),
                ], $candidates),
            );
        }
        foreach ($notes as $note) {
            $io->note($note);
        }
        $io->writeln(sprintf('%d service(s): %d would get the inbound, %d skipped. Nothing was changed. Test ONE service first: --service=<id> --apply.', ...array_values($summary)));

        return Command::SUCCESS;
    }

    /** A dry run of --kind=remove: per service, what the client is on now and after. Only GETs; nothing is written. */
    private function dryRunRemove(Plan $plan, VpnInbound $target, InboundMoveScope $scope, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $notes = array_values(array_filter([
            $this->planner->removeTargetProblem($plan, $target),
            $this->settings->enabled() ? null : sprintf('The switch %s is off: --apply would be refused (this dry run works without it).', InboundMoveSettings::ENABLED),
            $this->multiInbound->enabled() ? null : sprintf('The switch %s is off: --apply would be refused (this dry run works without it).', MultiInboundSettings::ENABLED),
            null === ($open = $this->planner->openRunId((int) $plan->getId())) ? null : InboundMovePlanner::openRunRefusal($open),
        ]));
        if (!$json) {
            $io->title(sprintf('Plan #%d "%s": dry run, removing %s from existing services (nothing is written)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($target)));
        }
        $candidates = $this->planner->removeCandidates($plan, $target, $scope, true);
        $removing = count(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_REMOVE === $c->action));
        $summary = ['services' => count($candidates), 'would_remove' => $removing, 'skipped' => count($candidates) - $removing];
        if ($json) {
            $output->writeln((string) json_encode([
                'plan_id' => $plan->getId(),
                'plan' => $plan->getTitle(),
                'kind' => InboundMoveRun::KIND_REMOVE,
                'target_inbound_id' => $target->getId(),
                'target_inbound' => $target->getTitle(),
                'dry_run' => true,
                'notes' => $notes,
                'services' => array_map(static fn (InboundMoveCandidate $c): array => $c->toArray(), $candidates),
                'summary' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        if ([] === $candidates) {
            $io->writeln('No service of this plan (in this scope) has this inbound: nothing to remove.');
        } else {
            $list = static fn (?array $titles): string => null === $titles ? '?' : implode(', ', $titles);
            $io->table(
                ['Service', 'Status', 'Now on panel', 'After', 'Action', 'Note'],
                array_map(static fn (InboundMoveCandidate $c): array => [
                    '#'.$c->serviceId,
                    $c->status,
                    $list($c->inboundsNow),
                    $list($c->inboundsAfter),
                    InboundMoveCandidate::ACTION_REMOVE === $c->action ? 'remove' : 'skip',
                    (string) ($c->skipText ?? $c->panelNote ?? ''),
                ], $candidates),
            );
        }
        foreach ($notes as $note) {
            $io->note($note);
        }
        $io->writeln(sprintf('%d service(s): %d would lose the inbound, %d skipped. Nothing was changed. Test ONE service first: --service=<id> --apply.', ...array_values($summary)));

        return Command::SUCCESS;
    }

    private function resume(Plan $plan, int $runId, ?int $batchSize, ?int $pause, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $runPlan = $this->entityManager->getConnection()->fetchOne('SELECT plan_id FROM inbound_move_run WHERE id = ?', [$runId]);
        if (false === $runPlan || (int) $runPlan !== (int) $plan->getId()) {
            return $this->fail($io, $output, $json, sprintf('Run #%d is not a run of plan #%d.', $runId, (int) $plan->getId()));
        }
        if (!$this->settings->enabled()) {
            return $this->fail($io, $output, $json, sprintf('Moving services is switched off (%s): the run is not continued.', InboundMoveSettings::ENABLED));
        }
        if (!$json) {
            $io->title(sprintf(match ($this->runKind($runId)) {
                InboundMoveRun::KIND_ADD => 'Plan #%d "%s": continuing run #%d (services not reached yet, and links not refreshed yet)',
                InboundMoveRun::KIND_REMOVE => 'Plan #%d "%s": continuing run #%d (removals that are due, and links not refreshed yet)',
                default => 'Plan #%d "%s": continuing run #%d (moves not done yet, and old inbounds due for removal)',
            }, (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), $runId));
        }

        return $this->process($plan, $runId, $batchSize, $pause, $io, $output, $json);
    }

    /**
     * @param list<InboundMoveCandidate> $candidates
     */
    private function process(Plan $plan, int $runId, ?int $batchSize, ?int $pause, SymfonyStyle $io, OutputInterface $output, bool $json, array $candidates = []): int
    {
        $items = [];
        $pass = $this->runner->process($runId, $batchSize, $pause, static function (InboundMoveItem $item) use (&$items, $io, $json): void {
            $items[] = $item;
            if (!$json) {
                $io->writeln(sprintf('Service #%d: %s → %s: %s%s', $item->serviceId, $item->from, $item->to, $item->outcome, null === $item->text ? '' : ': '.$item->text));
            }
        });
        if (in_array($pass->outcome, [InboundMovePass::SWITCHED_OFF, InboundMovePass::NOT_OPEN, InboundMovePass::REFUSED], true)) {
            return $this->fail($io, $output, $json, (string) $pass->message);
        }
        $failed = $pass->counts['failed'] > 0 || 'paused' === $pass->runStatus;
        if ($json) {
            $output->writeln((string) json_encode([
                'plan_id' => $plan->getId(),
                'run_id' => $runId,
                'dry_run' => false,
                'skipped_at_creation' => array_map(static fn (InboundMoveCandidate $c): array => $c->toArray(), array_values(array_filter($candidates, static fn (InboundMoveCandidate $c): bool => InboundMoveCandidate::ACTION_SKIP === $c->action))),
                'services' => array_map(static fn (InboundMoveItem $i): array => $i->toArray(), $items),
                'pass' => $pass->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $failed ? self::EXIT_FAILED : Command::SUCCESS;
        }
        $c = $pass->counts;
        if (InboundMoveRun::KIND_REMOVE === $pass->kind) {
            $io->writeln(sprintf('Run #%d is %s%s. Removed %d, failed %d, skipped %d, remaining %d (of %d).%s', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $c['done'], $c['failed'], $c['skipped'], $c['remaining'], $c['planned'],
                0 === $pass->retirePending ? '' : sprintf(' %d service(s) keep the inbound until their grace period ends (the first at %s); then the scheduled task removes it (or now: --retire-now=%d --resume=%d).', $pass->retirePending, (string) $pass->retireNextAt, $runId, $runId)));
            if ($pass->snapshotsPending > 0) {
                $io->warning(sprintf('%d service(s) lost the inbound, but their links are not refreshed yet: the stored ones are hidden until then. Run again with --resume=%d.', $pass->snapshotsPending, $runId));
            }
            if ('running' === $pass->runStatus) {
                $io->note(sprintf('Run #%d is still open (services wait for their grace period, a retry or their links). Continue it later with: --resume=%d', $runId, $runId));
            } elseif ('paused' === $pass->runStatus) {
                $io->warning(sprintf('Run #%d is paused. Fix that, then: --resume=%d; or stop it: --cancel=%d, or put the inbound back: --undo=%d', $runId, $runId, $runId, $runId));
            }

            return $failed ? self::EXIT_FAILED : Command::SUCCESS;
        }
        if (InboundMoveRun::KIND_ADD === $pass->kind) {
            $io->writeln(sprintf('Run #%d is %s%s. Added %d, failed %d, skipped %d, remaining %d (of %d).', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $c['done'], $c['failed'], $c['skipped'], $c['remaining'], $c['planned']));
            if ($pass->snapshotsPending > 0) {
                $io->warning(sprintf('%d service(s) got the inbound but not its link yet (the panel did not answer). Their stored links still work. Run again with --resume=%d.', $pass->snapshotsPending, $runId));
            }
            if ('running' === $pass->runStatus) {
                $io->note(sprintf('Run #%d is still open (failed services wait for a retry, or links for a refresh). Continue it later with: --resume=%d', $runId, $runId));
            } elseif ('paused' === $pass->runStatus) {
                $io->warning(sprintf('Run #%d is paused. Fix that, then: --resume=%d; or stop it: --cancel=%d', $runId, $runId, $runId));
            }

            return $failed ? self::EXIT_FAILED : Command::SUCCESS;
        }
        $io->writeln(sprintf('Run #%d is %s%s. Moved %d, failed %d, skipped %d, remaining %d (of %d).', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $c['done'], $c['failed'], $c['skipped'], $c['remaining'], $c['planned']));
        $io->writeln(sprintf('Old inbound removed in this pass: %d.%s%s', $pass->retired,
            0 === $pass->retirePending ? '' : sprintf(' %d moved service(s) keep the old inbound until their grace period ends (the first at %s); then run again with --resume=%d (or remove it now: --retire-now=%d --resume=%d).', $pass->retirePending, (string) $pass->retireNextAt, $runId, $runId, $runId),
            0 === $pass->keptOld ? '' : sprintf(' %d moved service(s) keep the old inbound (--keep-old: only --retire-now removes it; or it is one of their additional inbounds).', $pass->keptOld)));
        if ($pass->snapshotsPending > 0) {
            $io->warning(sprintf('%d moved service(s) still show their old links (the panel did not give new ones). Run again with --resume=%d, or use "Regenerate config" on them.', $pass->snapshotsPending, $runId));
        }
        if ('running' === $pass->runStatus) {
            $io->note(sprintf('Run #%d is still open (failed services wait for a retry, or old inbounds for their removal). Continue it later with: --resume=%d', $runId, $runId));
        } elseif ('paused' === $pass->runStatus) {
            $io->warning(sprintf('Run #%d paused itself. When the panel answers again: --resume=%d', $runId, $runId));
        }

        return $failed ? self::EXIT_FAILED : Command::SUCCESS;
    }

    private function undo(Plan $plan, int $runId, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        if (!$this->settings->enabled()) {
            return $this->fail($io, $output, $json, sprintf('Moving services is switched off (%s): nothing was undone.', InboundMoveSettings::ENABLED));
        }
        $kind = $this->runKind($runId);
        $add = InboundMoveRun::KIND_ADD === $kind;
        $remove = InboundMoveRun::KIND_REMOVE === $kind;
        if (!$json) {
            $io->title(sprintf(match ($kind) {
                InboundMoveRun::KIND_ADD => 'Plan #%d "%s": undo of run #%d (the added inbound comes off the services)',
                InboundMoveRun::KIND_REMOVE => 'Plan #%d "%s": undo of run #%d (the removed inbound goes back on the services)',
                default => 'Plan #%d "%s": undo of run #%d (moved services back on their old inbound)',
            }, (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), $runId));
        }
        $items = [];
        $pass = $this->runner->undo($runId, static function (InboundMoveItem $item) use (&$items, $io, $json): void {
            $items[] = $item;
            if (!$json) {
                $io->writeln(sprintf('Service #%d: %s → %s: %s%s', $item->serviceId, $item->from, $item->to, $item->outcome, null === $item->text ? '' : ': '.$item->text));
            }
        });
        if (InboundMovePass::RAN !== $pass->outcome) {
            return $this->fail($io, $output, $json, (string) $pass->message);
        }
        $retiredText = 0 === $pass->alreadyRetired ? null : InboundMoveControls::alreadyRetiredText($pass->alreadyRetired, (int) $plan->getId());
        $incomplete = $pass->failed > 0 || $pass->busy > 0 || $pass->alreadyRetired > 0;
        if ($json) {
            $output->writeln((string) json_encode([
                'plan_id' => $plan->getId(),
                'run_id' => $runId,
                'undo' => true,
                'services' => array_map(static fn (InboundMoveItem $i): array => $i->toArray(), $items),
                'already_retired_note' => $retiredText,
                'pass' => $pass->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $incomplete ? self::EXIT_FAILED : Command::SUCCESS;
        }
        if ($remove) {
            $io->writeln(sprintf('Run #%d is %s%s. Inbound put back: %d; not undone: %d failed, %d skipped, %d in another process\'s hands; services not reached yet that keep it: %d.', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $pass->undone, $pass->failed, $pass->skipped, $pass->busy, $pass->cancelled));
            if ($pass->undone > 0 && !$this->planStillSells($plan, $runId)) {
                $io->note('The plan still does not sell this inbound: new sales do not get it, and these services hold an extra inbound. An undo leaves the plan as it is.');
            }
        } elseif ($add) {
            $io->writeln(sprintf('Run #%d is %s%s. Inbound taken off: %d; not undone: %d failed, %d skipped, %d in another process\'s hands; services not reached yet that will not get it: %d; left alone: %d (they had it before the run).', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $pass->undone, $pass->failed, $pass->skipped, $pass->busy, $pass->cancelled, $pass->leftAlone));
            if ($pass->undone > 0 && $this->planStillSells($plan, $runId)) {
                $io->note('The plan still sells this inbound: new sales get it. An undo leaves the plan as it is.');
            }
        } else {
            $io->writeln(sprintf('Run #%d is %s%s. Put back on the old inbound: %d; not undone: %d failed, %d skipped, %d in another process\'s hands; services not moved yet that will not be moved: %d.', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $pass->undone, $pass->failed, $pass->skipped, $pass->busy, $pass->cancelled));
        }
        if (null !== $retiredText) {
            $io->warning($retiredText);
        }
        if ($pass->failed > 0 || $pass->busy > 0) {
            $io->warning(sprintf('Not every service is back yet: run --undo=%d again (it continues where it stopped).', $runId));
        }

        return $incomplete ? self::EXIT_FAILED : Command::SUCCESS;
    }

    /**
     * @param array{ok: bool, message: string, next?: string|null} $result
     */
    private function control(array $result, SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        if (!$result['ok']) {
            return $this->fail($io, $output, $json, $result['message']);
        }
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $io->writeln($result['message']);
        if (null !== ($result['next'] ?? null)) {
            $io->writeln((string) $result['next']);
        }

        return Command::SUCCESS;
    }

    private function runOfPlan(int $runId, Plan $plan): bool
    {
        $runPlan = $this->entityManager->getConnection()->fetchOne('SELECT plan_id FROM inbound_move_run WHERE id = ?', [$runId]);

        return false !== $runPlan && (int) $runPlan === (int) $plan->getId();
    }

    private function runKind(int $runId): ?string
    {
        $kind = $this->entityManager->getConnection()->fetchOne('SELECT kind FROM inbound_move_run WHERE id = ?', [$runId]);

        return false === $kind ? null : (string) $kind;
    }

    private function planStillSells(Plan $plan, int $runId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM plan_additional_inbound p JOIN inbound_move_run r ON r.target_inbound_id = p.inbound_id WHERE r.id = ? AND p.plan_id = ?',
            [$runId, $plan->getId()],
        );
    }

    private function latestRunId(Plan $plan): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT MAX(id) FROM inbound_move_run WHERE plan_id = ?', [$plan->getId()]);

        return null === $id || false === $id ? null : (int) $id;
    }

    private function fail(SymfonyStyle $io, OutputInterface $output, bool $json, string $message, int $code = self::EXIT_NOT_STARTED): int
    {
        if ($json) {
            $output->writeln((string) json_encode(['error' => $message, 'exit' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $io->error($message);
        }

        return $code;
    }

    private static function inbound(?VpnInbound $inbound): string
    {
        return null === $inbound ? '(none)' : sprintf('"%s" (#%d)', mb_substr($inbound->getTitle(), 0, 60), (int) $inbound->getId());
    }
}
