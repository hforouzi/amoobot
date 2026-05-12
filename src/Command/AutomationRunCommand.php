<?php

declare(strict_types=1);

namespace App\Command;

use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\ServiceAutoSuspendService;
use App\Provisioning\Application\ServiceExpiryChecker;
use App\Provisioning\Application\ServiceNotificationService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Shop\Application\IncompleteOrderExpiryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:automation:run', description: 'Run service lifecycle automation tasks in one safe command')]
final class AutomationRunCommand extends Command
{
    public function __construct(
        private readonly AutomationSettingsProvider $automationSettingsProvider,
        private readonly ServiceUsageSyncService $serviceUsageSyncService,
        private readonly ServiceExpiryChecker $serviceExpiryChecker,
        private readonly ServiceAutoSuspendService $serviceAutoSuspendService,
        private readonly ServiceNotificationService $serviceNotificationService,
        private readonly IncompleteOrderExpiryService $incompleteOrderExpiryService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show automation results without saving/sending')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max services to process per task; falls back to automation.batch_limit', null)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run only orders|sync|expiry|notifications|suspend|all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $only = strtolower(trim((string) $input->getOption('only')));
        $onlyOptions = ['orders', 'sync', 'expiry', 'notifications', 'suspend', 'all'];

        if (!in_array($only, $onlyOptions, true)) {
            $io->error('--only option must be one of: orders, sync, expiry, notifications, suspend, all.');

            return Command::FAILURE;
        }

        $limitOption = $input->getOption('limit');
        $limit = null !== $limitOption ? max(1, (int) $limitOption) : $this->automationSettingsProvider->batchLimit();

        $checked = 0;
        $updated = 0;
        $suspended = 0;
        $notified = 0;
        $failed = 0;
        $skipped = 0;

        if ($this->shouldRun($only, 'orders')) {
            if ($this->automationSettingsProvider->expireIncompleteOrdersEnabled()) {
                $summary = $this->incompleteOrderExpiryService->expire($dryRun, null, $limit);
                $updated += (int) $summary['draftsExpired'] + (int) $summary['ordersExpired'];
                $this->logger->info('automation_task_completed', ['task' => 'expire_incomplete_orders', 'summary' => $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'expire_incomplete_orders']);
            }
        }

        if ($this->shouldRun($only, 'sync')) {
            if ($this->automationSettingsProvider->syncUsageEnabled()) {
                $summary = $this->serviceUsageSyncService->syncActiveServices($limit, $dryRun);
                $checked += $summary->checked;
                $updated += $summary->updated;
                $failed += $summary->failed;
                $skipped += $summary->skipped;
                $this->logger->info('automation_task_completed', ['task' => 'sync_usage', 'summary' => (array) $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'sync_usage']);
            }
        }

        if ($this->shouldRun($only, 'expiry')) {
            if ($this->automationSettingsProvider->checkExpiryEnabled()) {
                $summary = $this->serviceExpiryChecker->checkAll($dryRun, $limit);
                $checked += $summary->checked;
                $updated += $summary->updated;
                $failed += $summary->failed;
                $skipped += $summary->skipped;
                $this->logger->info('automation_task_completed', ['task' => 'check_expiry', 'summary' => (array) $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'check_expiry']);
            }
        }

        if ($this->shouldRun($only, 'suspend')) {
            if ($this->automationSettingsProvider->autoSuspendExpiredEnabled()) {
                $summary = $this->serviceAutoSuspendService->suspendExpiredServices($limit, $dryRun);
                $checked += $summary->checked;
                $suspended += $summary->suspended;
                $failed += $summary->failed;
                $skipped += $summary->skipped;
                $this->logger->info('automation_task_completed', ['task' => 'auto_suspend_expired', 'summary' => (array) $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'auto_suspend_expired']);
            }

            if ($this->automationSettingsProvider->autoSuspendTrafficExhaustedEnabled()) {
                $summary = $this->serviceAutoSuspendService->suspendTrafficExhaustedServices($limit, $dryRun);
                $checked += $summary->checked;
                $suspended += $summary->suspended;
                $failed += $summary->failed;
                $skipped += $summary->skipped;
                $this->logger->info('automation_task_completed', ['task' => 'auto_suspend_traffic_exhausted', 'summary' => (array) $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'auto_suspend_traffic_exhausted']);
            }
        }

        if ($this->shouldRun($only, 'notifications')) {
            if ($this->automationSettingsProvider->sendNotificationsEnabled()) {
                $summary = $this->serviceNotificationService->sendExpiryWarningsWithOptions($dryRun, $limit)
                    ->merge($this->serviceNotificationService->sendTrafficWarningsWithOptions($dryRun, $limit))
                    ->merge($this->serviceNotificationService->sendExpiredNotificationsWithOptions($dryRun, $limit));
                $checked += $summary->checked;
                $notified += $summary->sent;
                $failed += $summary->failed;
                $skipped += $summary->skipped;
                $this->logger->info('automation_task_completed', ['task' => 'send_notifications', 'summary' => (array) $summary]);
            } else {
                $this->logger->info('automation_task_skipped_by_setting', ['task' => 'send_notifications']);
            }
        }

        $io->section('Summary');
        $io->listing([
            sprintf('checked: %d', $checked),
            sprintf('updated: %d', $updated),
            sprintf('suspended: %d', $suspended),
            sprintf('notified: %d', $notified),
            sprintf('failed: %d', $failed),
            sprintf('skipped: %d', $skipped),
        ]);

        if ($dryRun) {
            $io->note('Dry-run mode: no data was saved and no notifications were sent (counters show simulated actions).');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function shouldRun(string $only, string $task): bool
    {
        return 'all' === $only || $only === $task;
    }
}
