<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Payment\Application\Repair\StuckPayment;
use App\Payment\Application\Repair\StuckPaymentFinder;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finds CONFIRMED payments whose order was never fulfilled and, with --apply, moves them to
 * PROVISIONING_FAILED so they appear in the admin "provisioning failed" list with a Retry action.
 *
 * Dry-run by default. Nothing is provisioned or renewed here. Renewal / add-traffic orders are only
 * moved when the panel's current expiry/traffic still equals the database (the renewal provably did
 * not reach the panel); otherwise they are listed for manual reconciliation.
 */
#[AsCommand(name: 'app:payments:repair-stuck', description: 'List (and with --apply, mark for retry) confirmed payments whose order was never fulfilled')]
final class PaymentsRepairStuckCommand extends Command
{
    public const SOURCE = 'repair_stuck_command';

    public function __construct(
        private readonly StuckPaymentFinder $finder,
        private readonly PaymentSettlementService $settlement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Move retryable payments to provisioning_failed (default: dry-run, nothing is written)')
            ->addOption('include-service-missing', null, InputOption::VALUE_NONE, 'Also mark new-service orders that are marked provisioned but have no service row (check first: an admin may have deleted the service)')
            ->addOption('min-age-minutes', null, InputOption::VALUE_REQUIRED, 'Only payments confirmed at least this long ago', '15')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max stuck payments to handle', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $includeServiceMissing = (bool) $input->getOption('include-service-missing');
        $minAge = max(0, (int) $input->getOption('min-age-minutes'));
        $limit = max(1, (int) $input->getOption('limit'));

        $stuck = $this->finder->find(new \DateTimeImmutable(sprintf('-%d minutes', $minAge)), $limit);
        $io->title(sprintf('Stuck confirmed payments (%s)', $apply ? 'APPLY' : 'dry-run'));
        if ([] === $stuck) {
            $io->success('No stuck confirmed payments found.');

            return Command::SUCCESS;
        }

        $rows = [];
        $marked = 0;
        $failed = 0;
        foreach ($stuck as $item) {
            $retryable = $item->category->isRetryable($includeServiceMissing);
            $action = $this->action($item, $retryable, $apply);
            if ('marked' === $action) {
                ++$marked;
            } elseif (str_starts_with($action, 'error')) {
                ++$failed;
            }

            $order = $item->payment->getOrder();
            $rows[] = [
                $item->payment->getId(),
                $order->getId(),
                $order->getType(),
                $item->category->value,
                $action,
                $item->detail,
            ];
        }

        $io->table(['Payment', 'Order', 'Type', 'Category', 'Action', 'Detail'], $rows);
        $io->listing([
            sprintf('found: %d', count($stuck)),
            sprintf('marked provisioning_failed: %d', $marked),
            sprintf('errors: %d', $failed),
        ]);

        if (!$apply) {
            $io->note('Dry-run: nothing was changed. Re-run with --apply to mark the "would mark" rows.');
        } else {
            $io->note('Marked payments are in Admin > Payments: provisioning failed. Use "Retry provisioning" there.');
        }
        $io->writeln('Renewal rows that are not marked must be reconciled by hand: compare the panel with the order before doing anything.');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function action(StuckPayment $item, bool $retryable, bool $apply): string
    {
        if (!$retryable) {
            return 'skip (manual)';
        }
        if (!$apply) {
            return 'would mark';
        }

        $result = $this->settlement->markStuckForRetry(
            $item->payment,
            fn (Order $order): bool => $this->finder->isStillUnfulfilled($order, $item->category),
            $item->detail,
            self::SOURCE,
        );

        return match ($result->outcome) {
            SettlementOutcome::PROVISIONING_FAILED => 'marked',
            SettlementOutcome::ALREADY_PROCESSED => 'skip (no longer stuck)',
            default => 'error: '.$result->message,
        };
    }
}
