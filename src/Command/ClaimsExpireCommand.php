<?php

declare(strict_types=1);

namespace App\Command;

use App\Reseller\Application\ClaimMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks open claim links past their expiry as expired and retries the panel updates of claimed services that are due
 * (ClaimMaintenance). Scheduled as app:automation:run --only=claims; safe to run by hand at any time.
 */
#[AsCommand(name: 'app:claims:expire', description: 'Expire claim links past their expiry and retry pending panel updates of claimed services')]
final class ClaimsExpireCommand extends Command
{
    public function __construct(private readonly ClaimMaintenance $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows per step', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitText = (string) $input->getOption('limit');
        if (1 !== preg_match('/^[1-9]\d{0,5}$/D', $limitText)) {
            $io->error('--limit must be a whole number between 1 and 999999.');

            return Command::INVALID;
        }
        $limit = (int) $limitText;
        $dryRun = (bool) $input->getOption('dry-run');

        $expired = $this->maintenance->expire($limit, $dryRun);
        $sync = $this->maintenance->retryPanelSync($limit, $dryRun);
        $io->listing([
            sprintf('expired: %d', $expired),
            sprintf('panel updates tried: %d (synced %d, skipped %d, failed %d)', $sync['tried'], $sync['synced'], $sync['skipped'], $sync['failed']),
        ]);
        if ($dryRun) {
            $io->note('Dry-run: nothing was changed.');
        }

        // A panel that is down is not the command's failure: the next run tries again.
        return Command::SUCCESS;
    }
}
