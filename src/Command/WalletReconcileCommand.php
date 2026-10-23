<?php

declare(strict_types=1);

namespace App\Command;

use App\Wallet\Application\WalletReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compares every wallet's cached balance with its ledger, and payments with the ledger (WalletReconciler), and lists
 * the differences class by class with their ids. Never fixes anything: a mismatch means something wrote outside
 * WalletService / the payment flows and needs a person (a correction is an ADJUSTMENT through the admin, or a payment
 * decision). Exit code 1 when anything is wrong, so a cron/monitoring job can alert.
 */
#[AsCommand(name: 'app:wallet:reconcile', description: 'Check wallet balances and payments against the ledger (report only, never fixes)')]
final class WalletReconcileCommand extends Command
{
    public function __construct(private readonly WalletReconciler $reconciler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->reconciler->report();
        if ([] === $report['balances'] && [] === $report['payments']) {
            $io->success('Every wallet balance matches its ledger, and every payment matches its ledger rows.');

            return Command::SUCCESS;
        }

        if ([] !== $report['balances']) {
            $io->section('Balances against the ledger');
            $io->table(
                ['Wallet', 'User', 'Check', 'Stored', 'Ledger says'],
                array_map(static fn (array $m): array => ['#'.$m['walletId'], '#'.$m['userId'], $m['problem'], (string) $m['cached'], (string) $m['expected']], $report['balances'])
            );
        }
        if ([] !== $report['payments']) {
            $io->section('Payments against the ledger');
            $io->table(
                ['Check', 'Payment', 'Ledger row', 'Wallet', 'Detail'],
                array_map(static fn (array $m): array => [$m['problem'], null === $m['paymentId'] ? '-' : '#'.$m['paymentId'], null === $m['transactionId'] ? '-' : '#'.$m['transactionId'], null === $m['walletId'] ? '-' : '#'.$m['walletId'], $m['detail']], $report['payments'])
            );
        }
        $io->error(sprintf('%d mismatch(es). Nothing was changed; check each one, then correct it with an admin adjustment or a payment decision.', count($report['balances']) + count($report['payments'])));

        return Command::FAILURE;
    }
}
