<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\SyncResult;
use App\Provisioning\Application\SyncSummary;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteClientRef;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
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
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
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
        $showDiagnostics = $output->isDebug();

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
            if ($showDiagnostics) {
                $io->writeln($this->buildServiceDiagnosticsLine($service, $result));
            }
            $summary = new SyncSummary(
                checked: 1,
                updated: $result->isUpdated() ? 1 : 0,
                failed: $result->isFailed() ? 1 : 0,
                skipped: $result->isSkipped() ? 1 : 0,
            );
        } else {
            $summary = $this->syncBatchWithDiagnostics($limit, $dryRun, $showDiagnostics, $io);
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

    private function syncBatchWithDiagnostics(int $limit, bool $dryRun, bool $showDiagnostics, SymfonyStyle $io): SyncSummary
    {
        /** @var VpnService[] $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->orderBy('service.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $checked = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            $result = $this->serviceUsageSyncService->syncOne($service, $dryRun, false);
            ++$checked;
            if ($result->isUpdated()) {
                ++$updated;
            } elseif ($result->isFailed()) {
                ++$failed;
            } elseif ($result->isSkipped()) {
                ++$skipped;
            }

            if ($showDiagnostics) {
                $io->writeln($this->buildServiceDiagnosticsLine($service, $result));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new SyncSummary($checked, $updated, $failed, $skipped);
    }

    private function buildServiceDiagnosticsLine(VpnService $service, SyncResult $result): string
    {
        $panel = $service->getPanel();
        $inbound = $service->getInbound();
        $remoteId = trim((string) $service->getRemoteId());
        $remoteParse = $this->formatRemoteParse($remoteId);
        $usernameOrEmail = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? '-'));

        return sprintf(
            '[diag] service_id=%d outcome=%s status="%s" panel_id=%s panel_type="%s" inbound_id=%s user="%s" remote_parse="%s"%s%s',
            $service->getId() ?? 0,
            $result->outcome,
            $service->getStatus(),
            null !== $panel ? (string) ($panel->getId() ?? 'null') : 'null',
            $panel?->getType() ?? '-',
            null !== $inbound ? (string) ($inbound->getId() ?? 'null') : 'null',
            $usernameOrEmail,
            $remoteParse,
            $result->isSkipped() ? sprintf(' skip_reason="%s"', $result->message ?? '-') : '',
            $result->isFailed() ? sprintf(' error="%s"', $result->message ?? '-') : ''
        );
    }

    private function formatRemoteParse(string $remoteId): string
    {
        if ('' === $remoteId) {
            return 'empty_remote_id';
        }

        $parsed = $this->remoteIdParser->parse($remoteId);
        if (!$parsed instanceof Sanaei3xuiRemoteClientRef) {
            return 'parse_failed';
        }

        return sprintf(
            'ok(inbound=%s,uuid=%s,email=%s,panel=%s,localInbound=%s)',
            $parsed->inboundId,
            $this->truncate($parsed->clientId, 32),
            $parsed->email,
            null !== $parsed->panelId ? (string) $parsed->panelId : 'null',
            null !== $parsed->localInboundId ? (string) $parsed->localInboundId : 'null'
        );
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }
}
