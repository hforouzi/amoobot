<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Provisioning\Application\InboundMoveCandidate;
use App\Provisioning\Application\InboundMoveItem;
use App\Provisioning\Application\InboundMovePass;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveRunner;
use App\Provisioning\Application\InboundMoveScope;
use App\Provisioning\Application\SafeText;
use App\Shop\Application\InboundMoveSettings;
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
 * Moves a plan's existing services to the plan's current primary inbound, phase 1 only (docs/design/
 * plan-inbound-change.md 4.3 steps 1-4, 5.2, 6.2; PR P4): attach the new inbound, switch the service to it, refresh
 * its links. The old inbound is never removed in this release. Dry run unless --apply. See the help text.
 *
 * Exit codes: 0 every service moved or skipped; 1 some failed (or the run paused); 2 could not start.
 */
#[AsCommand(name: 'app:plan:apply-inbounds', description: 'Move a plan\'s existing services to its current inbound (phase 1: attach, switch, refresh links; dry run unless --apply)')]
final class PlanApplyInboundsCommand extends Command
{
    private const EXIT_FAILED = 1;
    private const EXIT_NOT_STARTED = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InboundMovePlanner $planner,
        private readonly InboundMoveRunner $runner,
        private readonly InboundMoveSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('planId', InputArgument::REQUIRED, 'The plan whose existing services are moved to its current primary inbound')
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
            ->addOption('resume', null, InputOption::VALUE_REQUIRED, 'Continue this open run of the plan (paused, or interrupted); it writes, like --apply')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
                Moves the existing services of a plan to the plan's CURRENT primary inbound. This release does phase 1
                only, on modern 3x-ui panels:
                  1. the client is attached to the new inbound as well (nothing is sent when it already is);
                  2. the service is switched to the new inbound in the bot (inbound, remote id);
                  3. its links are refreshed from the panel (subLinks: the old and the new inbound).
                The OLD INBOUND STAYS ATTACHED: nothing is ever detached in this release. The customer's UUID, email,
                subscription id and URL, expiry, limits and traffic are not changed.

                Without --apply it is a DRY RUN: it writes nothing and only reads the panel (one GET per service,
                time-limited) to show who is already on the new inbound. It works with the switch off.

                Recommended order:
                  php bin/console app:plan:apply-inbounds <plan>                        (dry run, all services)
                  php bin/console app:plan:apply-inbounds <plan> --service=<id> --apply (ONE service first)
                  check that service (panel, links, the customer's app), then
                  php bin/console app:plan:apply-inbounds <plan> --apply                (the rest)

                --apply needs plans.inbound_move_enabled on (Settings -> Plans). One run per plan is open at a time;
                a second --apply is refused and names it. A run that paused itself (5 failed panel reads in a row)
                or was interrupted is continued with --resume=<runId>. Failed services are retried with the attach
                retry settings (plans.attach_max_attempts, plans.attach_retry_delay_minutes) on the next --resume.

                Scope: active services only unless --include-suspended / --include-expired / --include-trials;
                deleted ones never. Skipped, with the reason: services on another panel, on a legacy (not modern)
                panel or the dummy driver, in another unfinished run, moved 3 times within 24 hours, or whose
                inbounds would break the plan's protocol rules.

                The output lists service ids and inbound titles only: never links, UUIDs, tokens or customers.
                Exit codes: 0 done (moved or skipped), 1 some failed or the run paused, 2 could not start.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        try {
            return $this->runCommand($input, $io, $json, $output);
        } catch (\Throwable $e) {
            $text = SafeText::of($e->getMessage());
            $this->logger->error('inbound_move_command_failed', ['error' => $text, 'class' => $e::class]);
            $this->fail($io, $output, $json, 'Stopped: '.$text, self::EXIT_FAILED);

            return self::EXIT_FAILED;
        }
    }

    private function runCommand(InputInterface $input, SymfonyStyle $io, bool $json, OutputInterface $output): int
    {
        $ints = [];
        foreach (['planId' => (string) $input->getArgument('planId'), 'service' => $input->getOption('service'), 'from-inbound' => $input->getOption('from-inbound'), 'batch-size' => $input->getOption('batch-size'), 'pause' => $input->getOption('pause'), 'limit' => $input->getOption('limit'), 'after-id' => $input->getOption('after-id'), 'resume' => $input->getOption('resume')] as $name => $value) {
            if (null === $value) {
                $ints[$name] = null;
                continue;
            }
            if (1 !== preg_match('/^\d{1,9}$/D', (string) $value) || ('pause' !== $name && 'after-id' !== $name && 0 === (int) $value)) {
                return $this->fail($io, $output, $json, sprintf('--%s must be a whole number%s.', $name, 'pause' === $name || 'after-id' === $name ? '' : ' above 0'));
            }
            $ints[$name] = (int) $value;
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

        if (null !== $ints['resume']) {
            $scoped = array_merge(array_filter(['service', 'from-inbound', 'limit', 'after-id'], static fn (string $o): bool => null !== $ints[$o]),
                array_filter(['include-suspended', 'include-expired', 'include-trials'], static fn (string $o): bool => (bool) $input->getOption($o)));

            return [] !== $scoped
                ? $this->fail($io, $output, $json, 'A run\'s scope was frozen when it was created: --resume takes no scope option (--'.implode(', --', $scoped).').')
                : $this->resume($plan, (int) $ints['resume'], $ints['batch-size'], $ints['pause'], $io, $output, $json);
        }
        if (!(bool) $input->getOption('apply')) {
            return $this->dryRun($plan, $scope, $io, $output, $json);
        }

        $created = $this->planner->create($plan, $scope);
        if (is_string($created)) {
            return $this->fail($io, $output, $json, $created);
        }
        [$runId, $candidates] = $created;
        if (!$json) {
            $io->title(sprintf('Plan #%d "%s": moving existing services to %s (run #%d, phase 1, the old inbound stays)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), self::inbound($plan->getInbound()), $runId));
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
                    InboundMoveCandidate::ACTION_MOVE === $c->action ? 'attach + switch (old stays)' : 'skip',
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
            $io->title(sprintf('Plan #%d "%s": continuing run #%d (phase 1, the old inbound stays)', (int) $plan->getId(), mb_substr($plan->getTitle(), 0, 60), $runId));
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
        $io->writeln(sprintf('Run #%d is %s%s. Moved %d, failed %d, skipped %d, remaining %d (of %d). The old inbound stays attached to every moved client.', $runId, $pass->runStatus, null === $pass->pauseReason ? '' : ' ('.$pass->pauseReason.')', $c['done'], $c['failed'], $c['skipped'], $c['remaining'], $c['planned']));
        if ($pass->snapshotsPending > 0) {
            $io->warning(sprintf('%d moved service(s) still show their old links (the panel did not give new ones). Run again with --resume=%d, or use "Regenerate config" on them.', $pass->snapshotsPending, $runId));
        }
        if ('running' === $pass->runStatus) {
            $io->note(sprintf('Run #%d is still open (failed services wait for a retry). Continue it later with: --resume=%d', $runId, $runId));
        } elseif ('paused' === $pass->runStatus) {
            $io->warning(sprintf('Run #%d paused itself. When the panel answers again: --resume=%d', $runId, $runId));
        }

        return $failed ? self::EXIT_FAILED : Command::SUCCESS;
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
