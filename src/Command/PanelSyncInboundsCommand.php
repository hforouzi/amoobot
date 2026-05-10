<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnInboundSyncService;
use App\Provisioning\Domain\VpnServiceStatus;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnInboundSyncService $inboundSyncService,
        private readonly Sanaei3xuiApiClient $apiClient,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force overwrite parsed technical metadata.')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Print sanitized raw inbound JSON.')
            ->addOption('regenerate-configs', null, InputOption::VALUE_NONE, 'After sync, regenerate configText for all services of each synced inbound.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelId = (int) $input->getArgument('panelId');
        $force = (bool) $input->getOption('force');
        $raw = (bool) $input->getOption('raw');
        $regenerateConfigs = (bool) $input->getOption('regenerate-configs');

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
            $io->section('Regenerating service configs for synced inbounds…');
            $inbounds = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel]);
            $totalUpdated = 0;
            $totalSkipped = 0;
            $totalFailed = 0;

            foreach ($inbounds as $inbound) {
                /** @var VpnService[] $services */
                $services = $this->entityManager->getRepository(VpnService::class)->findBy(['inbound' => $inbound]);
                foreach ($services as $service) {
                    if (VpnServiceStatus::DELETED === $service->getStatus()) {
                        ++$totalSkipped;
                        continue;
                    }
                    $uuid = $service->getClientUuid();
                    $subId = $service->getSubId();
                    if (null === $uuid || '' === $uuid || null === $subId || '' === $subId) {
                        ++$totalSkipped;
                        continue;
                    }
                    try {
                        $links = $this->vpnAccessLinkGenerator->generate($service);
                        $configLinks = array_values(array_filter(
                            (array) ($links['configLinks'] ?? []),
                            static fn (mixed $link): bool => '' !== trim((string) $link)
                        ));
                        $subscriptionUrl = $links['subscriptionUrl'] ?? null;
                        $finalConfigText = [] !== $configLinks ? implode("\n", $configLinks) : null;

                        $service
                            ->setConfigLinks($configLinks)
                            ->setConfigText($finalConfigText)
                            ->setSubscriptionUrl($subscriptionUrl ?? $service->getSubscriptionUrl())
                            ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

                        ++$totalUpdated;
                    } catch (\Throwable) {
                        ++$totalFailed;
                    }
                }
            }

            $this->entityManager->flush();
            $io->success(sprintf('Config regeneration done: %d updated, %d skipped, %d failed.', $totalUpdated, $totalSkipped, $totalFailed));
        } else {
            $io->note('اینباندها بروزرسانی شدند. اگر External Proxy تغییر کرده، کانفیگ سرویسها را بازسازی کنید: app:inbound:regenerate-service-configs {inboundId}');
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
