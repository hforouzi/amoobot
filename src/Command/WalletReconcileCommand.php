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
 * Compares every wallet's cached balance with its ledger and lists the differences. Never fixes anything: a mismatch
 * means something wrote outside WalletService and needs a person (a correction is an ADJUSTMENT through the admin).
 * Exit code 1 when anything is wrong, so a cron/monitoring job can alert.
 */
#[AsCommand(name: 'app:wallet:reconcile', description: 'Check wallet balances against the ledger (report only, never fixes)')]
final class WalletReconcileCommand extends Command
{
    public function __construct(private readonly WalletReconciler $reconciler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mismatches = $this->reconciler->mismatches();
        if ([] === $mismatches) {
            $io->success('Every wallet balance matches its ledger.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Wallet', 'User', 'Check', 'Stored', 'Ledger says'],
            array_map(static fn (array $m): array => ['#'.$m['walletId'], '#'.$m['userId'], $m['problem'], (string) $m['cached'], (string) $m['expected']], $mismatches)
        );
        $io->error(sprintf('%d mismatch(es). Nothing was changed; correct them with an admin adjustment after checking.', count($mismatches)));

        return Command::FAILURE;
    }
}
