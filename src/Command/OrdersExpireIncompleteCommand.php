<?php

declare(strict_types=1);

namespace App\Command;

use App\Shop\Application\IncompleteOrderExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:orders:expire-incomplete', description: 'Expire incomplete order drafts/orders/payments')]
final class OrdersExpireIncompleteCommand extends Command
{
    public function __construct(
        private readonly IncompleteOrderExpiryService $incompleteOrderExpiryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show results without saving')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Override expiration hours')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max drafts/orders to evaluate per run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hoursOption = $input->getOption('hours');
        $hours = null !== $hoursOption ? max(1, (int) $hoursOption) : null;
        $limit = max(1, (int) $input->getOption('limit'));

        $summary = $this->incompleteOrderExpiryService->expire($dryRun, $hours, $limit);
        $effectiveHours = $hours ?? $this->incompleteOrderExpiryService->configuredHours();

        $io->section('Expire incomplete orders');
        $io->listing([
            sprintf('hours: %d', $effectiveHours),
            sprintf('limit: %d', $limit),
            sprintf('drafts expired: %d', $summary['draftsExpired']),
            sprintf('orders expired: %d', $summary['ordersExpired']),
            sprintf('payments rejected: %d', $summary['paymentsRejected']),
            sprintf('orders skipped (submitted receipt): %d', $summary['ordersSkippedSubmittedReceipt']),
        ]);

        if ($dryRun) {
            $io->note('Dry-run mode: no data was saved.');
        }

        return Command::SUCCESS;
    }
}
