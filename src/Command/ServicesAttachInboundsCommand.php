<?php

declare(strict_types=1);

namespace App\Command;

use App\Provisioning\Application\InboundAttachRun;
use App\Provisioning\Application\InboundAttachScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The scheduled retry of additional inbounds by hand (InboundAttachScheduler): retries the failed ones that are due,
 * compares the others with the panel, alerts the admins. Scheduled as app:automation:run --only=attach-inbounds; safe
 * to run at any time, also next to the timer (the rows are claimed). Nothing happens while plans.multi_inbound_enabled
 * is off.
 */
#[AsCommand(name: 'app:services:attach-inbounds', description: 'Retry the additional inbounds of services that failed to attach, and compare the attached ones with the panel')]
final class ServicesAttachInboundsCommand extends Command
{
    public function __construct(private readonly InboundAttachScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max services in this run', '500')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Services per batch', '50')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Seconds between batches (0 to 60)', '0.2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the services that are due (no panel call, nothing written)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (string) $input->getOption('limit');
        $batchSize = (string) $input->getOption('batch-size');
        $pause = (string) $input->getOption('pause');
        if (1 !== preg_match('/^[1-9]\d{0,5}$/D', $limit) || 1 !== preg_match('/^[1-9]\d{0,3}$/D', $batchSize)) {
            $io->error('--limit must be a whole number between 1 and 999999, --batch-size between 1 and 9999.');

            return Command::INVALID;
        }
        if (!is_numeric($pause) || (float) $pause < 0 || (float) $pause > 60) {
            $io->error('--pause must be a number of seconds between 0 and 60.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $run = $this->scheduler->run((int) $limit, $dryRun, (int) $batchSize, (int) round(1000 * (float) $pause), static function (int $serviceId, string $what) use ($io): void {
            $io->writeln(sprintf('  service #%d: %s', $serviceId, $what));
        });

        if (InboundAttachRun::SWITCHED_OFF === $run->outcome) {
            $io->note('plans.multi_inbound_enabled is off: nothing was read or changed.');

            return Command::SUCCESS;
        }
        if (InboundAttachRun::DISABLED === $run->outcome) {
            $io->note(sprintf('plans.attach_retry_enabled is off: nothing was retried or checked (alerts sent: %d).', $run->alertsSent));

            return Command::SUCCESS;
        }
        foreach ($run->stoppedPanels as $panelId => $serviceId) {
            $io->warning(sprintf('Panel #%d: stopped after %d services in a row could not be read (is it down?); the next run tries again.', $panelId, InboundAttachScheduler::MAX_CONSECUTIVE_FAILURES));
        }
        $io->listing($dryRun ? [sprintf('services due: %d', $run->services)] : [
            sprintf('services: %d', $run->services),
            sprintf('attached: %d', $run->attached),
            sprintf('still failed: %d', $run->stillFailed),
            sprintf('found removed on the panel (retried): %d', $run->foundRemoved),
            sprintf('found attached on the panel: %d', $run->foundAttached),
            sprintf('client not on the panel: %d', $run->clientMissing),
            sprintf('panel not read: %d', $run->panelFailures),
            sprintf('skipped: %d', $run->skipped),
            sprintf('alerts sent: %d', $run->alertsSent),
        ]);
        if ($dryRun) {
            $io->note('Dry-run: no panel was called and nothing was changed.');
        }

        // A panel that is down is not the command's failure: the next run tries again.
        return Command::SUCCESS;
    }
}
