<?php

declare(strict_types=1);

namespace App\Command;

use App\Shop\Application\PlanPriceAdjustmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plans:adjust-prices', description: 'Bulk adjust plan prices with percent/amount delta')]
final class AdjustPlanPricesCommand extends Command
{
    public function __construct(
        private readonly PlanPriceAdjustmentService $planPriceAdjustmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('percent', null, InputOption::VALUE_REQUIRED, 'Percent delta (e.g. 10)')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Fixed amount delta')
            ->addOption('direction', null, InputOption::VALUE_REQUIRED, 'increase|decrease', 'increase')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'price|pricePerGb|pricePerDay|all', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $direction = strtolower((string) $input->getOption('direction'));
        $field = (string) $input->getOption('field');
        $dryRun = (bool) $input->getOption('dry-run');
        $percentRaw = $input->getOption('percent');
        $amountRaw = $input->getOption('amount');

        if (!in_array($direction, ['increase', 'decrease'], true)) {
            $io->error('direction must be increase or decrease.');

            return Command::FAILURE;
        }
        if (!in_array($field, ['price', 'pricePerGb', 'pricePerDay', 'all'], true)) {
            $io->error('field must be price|pricePerGb|pricePerDay|all.');

            return Command::FAILURE;
        }
        if ((null === $percentRaw && null === $amountRaw) || (null !== $percentRaw && null !== $amountRaw)) {
            $io->error('Provide exactly one of --percent or --amount.');

            return Command::FAILURE;
        }

        $percent = null;
        $amount = null;
        if (null !== $percentRaw) {
            $percent = (float) $percentRaw;
            if ($percent < 0) {
                $io->error('percent must be >= 0.');

                return Command::FAILURE;
            }
        } else {
            $amount = (int) $amountRaw;
            if ($amount < 0) {
                $io->error('amount must be >= 0.');

                return Command::FAILURE;
            }
        }

        $preview = $this->planPriceAdjustmentService->preview($direction, $field, $percent, $amount);
        $changes = $preview['changes'];

        if ([] === $changes) {
            $io->success('No price changes to apply.');

            return Command::SUCCESS;
        }

        $io->section($dryRun ? 'Dry-run changes' : 'Planned changes');
        foreach ($changes as $change) {
            /** @var Plan $plan */
            $plan = $change['plan'];
            $io->writeln(sprintf(
                'Plan #%d (%s) | %s: %d -> %d',
                $plan->getId() ?? 0,
                $plan->getTitle(),
                (string) $change['field'],
                (int) $change['before'],
                (int) $change['after'],
            ));
        }

        if ($dryRun) {
            $io->success(sprintf('Dry-run complete. %d change(s) detected.', count($changes)));

            return Command::SUCCESS;
        }

        if ($input->isInteractive() && !$io->confirm('Apply these changes?', false)) {
            $io->warning('Operation cancelled.');

            return Command::SUCCESS;
        }

        foreach ($changes as $change) {
            $plan = $change['plan'];
            error_log(sprintf(
                '[AdjustPlanPricesCommand] plan_id=%d field=%s before=%d after=%d',
                $plan->getId() ?? 0,
                (string) $change['field'],
                (int) $change['before'],
                (int) $change['after']
            ));
        }

        $this->planPriceAdjustmentService->apply($changes);
        $io->success(sprintf('Applied %d change(s).', count($changes)));

        return Command::SUCCESS;
    }
}
