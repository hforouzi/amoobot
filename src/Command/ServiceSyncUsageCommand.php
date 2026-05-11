<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\SyncSummary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:sync-usage', description: 'Sync VPN service usage from panel and update local lifecycle fields')]
final class ServiceSyncUsageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceUsageSyncService $serviceUsageSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('service-id', null, InputOption::VALUE_REQUIRED, 'Sync only one service by id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max services to process in batch mode', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show results without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceIdOption = $input->getOption('service-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(1, (int) $input->getOption('limit'));

        if (null !== $serviceIdOption && '' !== trim((string) $serviceIdOption)) {
            $serviceId = (int) $serviceIdOption;
            if ($serviceId <= 0) {
                $io->error('service-id must be greater than zero.');

                return Command::FAILURE;
            }

            $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
            if (!$service instanceof VpnService) {
                $io->error('VpnService not found.');

                return Command::FAILURE;
            }

            $result = $this->serviceUsageSyncService->syncOne($service, $dryRun);
            $summary = new SyncSummary(
                checked: 1,
                updated: $result->isUpdated() ? 1 : 0,
                failed: $result->isFailed() ? 1 : 0,
                skipped: $result->isSkipped() ? 1 : 0,
            );
        } else {
            $summary = $this->serviceUsageSyncService->syncActiveServices($limit, $dryRun);
        }

        $io->section('Summary');
        $io->listing([
            sprintf('checked: %d', $summary->checked),
            sprintf('updated: %d', $summary->updated),
            sprintf('failed: %d', $summary->failed),
            sprintf('skipped: %d', $summary->skipped),
        ]);

        if ($dryRun) {
            $io->note('Dry-run mode: no data was saved.');
        }

        return $summary->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
