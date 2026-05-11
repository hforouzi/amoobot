<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:plans:adjust-prices', description: 'Bulk adjust plan prices with percent/amount delta')]
final class PlansAdjustPricesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

            return Command::INVALID;
        }
        if (!in_array($field, ['price', 'pricePerGb', 'pricePerDay', 'all'], true)) {
            $io->error('field must be price|pricePerGb|pricePerDay|all.');

            return Command::INVALID;
        }
        if ((null === $percentRaw && null === $amountRaw) || (null !== $percentRaw && null !== $amountRaw)) {
            $io->error('Provide exactly one of --percent or --amount.');

            return Command::INVALID;
        }

        $percent = null;
        $amount = null;
        if (null !== $percentRaw) {
            $percent = (float) $percentRaw;
            if ($percent < 0) {
                $io->error('percent must be >= 0.');

                return Command::INVALID;
            }
        } else {
            $amount = (int) $amountRaw;
            if ($amount < 0) {
                $io->error('amount must be >= 0.');

                return Command::INVALID;
            }
        }

        /** @var list<Plan> $plans */
        $plans = $this->entityManager->getRepository(Plan::class)->findBy([], ['id' => 'ASC']);
        if ([] === $plans) {
            $io->warning('No plans found.');

            return Command::SUCCESS;
        }

        $fields = 'all' === $field ? ['price', 'pricePerGb', 'pricePerDay'] : [$field];
        $changes = [];
        foreach ($plans as $plan) {
            foreach ($fields as $targetField) {
                $before = $this->readField($plan, $targetField);
                if (null === $before) {
                    continue;
                }
                $after = $this->calculateAdjustedValue($before, $direction, $percent, $amount);
                if ($after === $before) {
                    continue;
                }
                $changes[] = [
                    'plan' => $plan,
                    'field' => $targetField,
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

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
            /** @var Plan $plan */
            $plan = $change['plan'];
            $this->writeField($plan, (string) $change['field'], (int) $change['after']);
            $plan->setUpdatedAt(new \DateTimeImmutable());
            error_log(sprintf(
                '[PlansAdjustPricesCommand] plan_id=%d field=%s before=%d after=%d',
                $plan->getId() ?? 0,
                (string) $change['field'],
                (int) $change['before'],
                (int) $change['after']
            ));
        }

        $this->entityManager->flush();
        $io->success(sprintf('Applied %d change(s).', count($changes)));

        return Command::SUCCESS;
    }

    private function calculateAdjustedValue(int $before, string $direction, ?float $percent, ?int $amount): int
    {
        $delta = null !== $percent
            ? (int) floor(($before * $percent) / 100)
            : max(0, (int) ($amount ?? 0));

        $after = 'increase' === $direction ? ($before + $delta) : ($before - $delta);

        return max(0, $after);
    }

    private function readField(Plan $plan, string $field): ?int
    {
        return match ($field) {
            'price' => $plan->getPrice(),
            'pricePerGb' => $plan->getPricePerGb(),
            'pricePerDay' => $plan->getPricePerDay(),
            default => null,
        };
    }

    private function writeField(Plan $plan, string $field, int $value): void
    {
        match ($field) {
            'price' => $plan->setPrice($value),
            'pricePerGb' => $plan->setPricePerGb($value),
            'pricePerDay' => $plan->setPricePerDay($value),
            default => null,
        };
    }
}
