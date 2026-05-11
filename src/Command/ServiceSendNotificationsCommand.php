<?php

declare(strict_types=1);

namespace App\Command;

use App\Provisioning\Application\NotificationSummary;
use App\Provisioning\Application\ServiceNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:send-notifications', description: 'Send lifecycle notifications for VPN services')]
final class ServiceSendNotificationsCommand extends Command
{
    public function __construct(
        private readonly ServiceNotificationService $serviceNotificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show notification results without sending/saving')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Notification type: expiry|traffic|expired|all', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max services to process', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $type = trim((string) $input->getOption('type'));
        $limit = max(1, (int) $input->getOption('limit'));

        if (!in_array($type, ['expiry', 'traffic', 'expired', 'all'], true)) {
            $io->error('type must be one of: expiry, traffic, expired, all.');

            return Command::FAILURE;
        }

        $total = new NotificationSummary(0, 0, 0, 0);

        if ('expiry' === $type || 'all' === $type) {
            $summary = $this->serviceNotificationService->sendExpiryWarningsWithOptions($dryRun, $limit);
            $total = $total->merge($summary);
            if ($output->isDebug()) {
                $io->writeln(sprintf('[diag] expiry checked=%d sent=%d skipped=%d failed=%d', $summary->checked, $summary->sent, $summary->skipped, $summary->failed));
            }
        }

        if ('traffic' === $type || 'all' === $type) {
            $summary = $this->serviceNotificationService->sendTrafficWarningsWithOptions($dryRun, $limit);
            $total = $total->merge($summary);
            if ($output->isDebug()) {
                $io->writeln(sprintf('[diag] traffic checked=%d sent=%d skipped=%d failed=%d', $summary->checked, $summary->sent, $summary->skipped, $summary->failed));
            }
        }

        if ('expired' === $type || 'all' === $type) {
            $summary = $this->serviceNotificationService->sendExpiredNotificationsWithOptions($dryRun, $limit);
            $total = $total->merge($summary);
            if ($output->isDebug()) {
                $io->writeln(sprintf('[diag] expired checked=%d sent=%d skipped=%d failed=%d', $summary->checked, $summary->sent, $summary->skipped, $summary->failed));
            }
        }

        $io->section('Summary');
        $io->listing([
            sprintf('checked: %d', $total->checked),
            sprintf('sent: %d', $total->sent),
            sprintf('skipped: %d', $total->skipped),
            sprintf('failed: %d', $total->failed),
        ]);

        if ($dryRun) {
            $io->note('Dry-run mode: no notifications were sent or saved.');
        }

        return $total->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

