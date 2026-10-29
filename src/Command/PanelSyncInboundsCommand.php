<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Application\ServiceLinkChange;
use App\Provisioning\Application\ServiceLinkRegenerator;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:sync-inbounds', description: 'Sync panel inbounds into local VpnInbound records')]
final class PanelSyncInboundsCommand extends Command
{
    use RegeneratesLinksInBatches;

    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnInboundSyncService $inboundSyncService,
        private readonly Sanaei3xuiApiClient $apiClient,
        private readonly ServiceLinkRegenerator $linkRegenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force overwrite parsed technical metadata.')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Print sanitized raw inbound JSON.')
            ->addOption('regenerate-configs', null, InputOption::VALUE_NONE, 'After sync, re-read the config links of every service of the panel\'s inbounds (a dry run unless --apply).')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'With --regenerate-configs: store the new links (without it nothing is written for the services)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --regenerate-configs: show old vs new links without writing them (the default)')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'With --regenerate-configs: resume after this service id', '0')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'With --regenerate-configs: services read per batch', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'With --regenerate-configs: seconds to wait between batches', self::DEFAULT_PAUSE_SECONDS)
            ->setHelp(<<<'HELP'
                Syncs the panel's inbounds into the local VpnInbound records (always written).

                With <info>--regenerate-configs</info> it then re-reads the config links of every service of those inbounds,
                exactly like app:inbound:regenerate-service-configs: <comment>a dry run unless --apply</comment>, in
                batches with a pause, each service written on its own, stopping after 5 services in a row failed;
                resume with <info>--after-id</info>. A bulk run never marks the panel "degraded".
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelId = (int) $input->getArgument('panelId');
        $force = (bool) $input->getOption('force');
        $raw = (bool) $input->getOption('raw');
        $regenerateConfigs = (bool) $input->getOption('regenerate-configs');
        $apply = $this->applyRequested($input, $io);
        if (null === $apply) {
            return Command::INVALID;
        }
        if (($apply || (bool) $input->getOption('dry-run')) && !$regenerateConfigs) {
            $io->error('--apply and --dry-run only apply to --regenerate-configs.');

            return Command::INVALID;
        }

        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($panelId);
        if (!$panel instanceof VpnPanel) {
            $io->error('Panel not found.');

            return Command::FAILURE;
        }

        if ('sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        if ($raw) {
            $result = $this->apiClient->listInbounds($panel);
            if (($result['ok'] ?? false) === true && is_array($result['data'] ?? null)) {
                $payload = $result['data'];
                $inbounds = $payload['obj'] ?? $payload;
                if (is_array($inbounds)) {
                    $sanitized = [];
                    foreach ($inbounds as $inbound) {
                        $sanitized[] = $this->sanitizeForRawOutput($inbound);
                    }

                    $io->writeln((string) json_encode(
                        $sanitized,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ));
                }
            } else {
                $io->warning('Unable to print raw payload: failed to fetch inbounds.');
            }
        }

        try {
            $sync = $this->inboundSyncService->syncPanelInbounds($panel, $force);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($sync->missingLocalCount > 0) {
            $io->warning(sprintf('%d local inbound(s) are missing on remote panel.', $sync->missingLocalCount));
        }

        $io->success(sprintf('Synced %d inbound(s).', $sync->syncedCount));

        if ($regenerateConfigs) {
            $io->section(sprintf('Service config links of the synced inbounds, %s', $apply ? 'APPLY (writes)' : 'dry run (nothing is written)'));
            $inboundIds = array_map('intval', $this->entityManager->createQueryBuilder()
                ->select('i.id')->from(VpnInbound::class, 'i')->where('i.panel = :panel')->setParameter('panel', $panel)
                ->orderBy('i.id', 'ASC')->getQuery()->getSingleColumnResult());
            $run = $this->regenerateInBatches($io, $inboundIds, 'console_panel_sync_regenerate_configs', $apply, max(0, (int) $input->getOption('after-id')),
                max(1, min(500, (int) $input->getOption('batch-size'))), self::pauseMs($input->getOption('pause')));
            $this->printSummary($io, $run['counts'], $apply);
            if ($run['stopped']) {
                return Command::FAILURE;
            }
            $io->success($apply ? 'Config links done.' : 'Dry run: no service was written. Run again with --regenerate-configs --apply to store the new links.');
            if (0 !== $run['counts'][ServiceLinkChange::FAILED] + $run['counts'][ServiceLinkChange::KEPT] + $run['counts'][ServiceLinkChange::CHANGED]) {
                return Command::FAILURE;
            }
        } else {
            $io->note('اینباندها بروزرسانی شدند. اگر External Proxy یا Hostها تغییر کرده، لینک‌های سرویس‌ها را دوباره بخوانید: اول بدون --apply (اجرای آزمایشی) app:inbound:regenerate-service-configs {inboundId} و بعد همان دستور با --apply.');
        }

        return Command::SUCCESS;
    }

    private function sanitizeForRawOutput(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(?:password|passwd|token|cookie|session|authorization)/i', $key)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }

                $sanitized[$key] = $this->sanitizeForRawOutput($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return '[object]';
        }

        return $value;
    }
}
