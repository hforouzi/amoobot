<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:sync-inbounds', description: 'Sync panel inbounds into local VpnInbound records')]
final class PanelSyncInboundsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiApiClient $apiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelId = (int) $input->getArgument('panelId');

        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($panelId);
        if (!$panel instanceof VpnPanel) {
            $io->error('Panel not found.');

            return Command::FAILURE;
        }

        if ('sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        $result = $this->apiClient->listInbounds($panel);
        if (($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            $io->error('Failed to fetch inbounds from panel.');

            return Command::FAILURE;
        }

        $payload = $result['data'];
        $rows = $payload['obj'] ?? $payload;
        if (!is_array($rows)) {
            $io->warning('No inbound payload found.');

            return Command::SUCCESS;
        }

        $remoteIds = [];
        $synced = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $remoteInboundId = trim((string) ($row['id'] ?? ''));
            if ('' === $remoteInboundId) {
                continue;
            }

            $remoteIds[] = $remoteInboundId;
            $inbound = $this->entityManager->getRepository(VpnInbound::class)->findOneBy([
                'panel' => $panel,
                'remoteInboundId' => $remoteInboundId,
            ]);

            if (!$inbound instanceof VpnInbound) {
                $inbound = (new VpnInbound())
                    ->setPanel($panel)
                    ->setRemoteInboundId($remoteInboundId);
                $this->entityManager->persist($inbound);
            }

            $parsed = $this->extractTransport($row);

            $inbound
                ->setTitle($this->deriveTitle($row, $remoteInboundId))
                ->setRemark($this->nullableText($row['remark'] ?? null))
                ->setProtocol($this->nullableText($row['protocol'] ?? null))
                ->setNetwork($parsed['network'])
                ->setSecurity($parsed['security'])
                ->setConfig(is_array($row) ? $row : null)
                ->setIsActive((bool) ($row['enable'] ?? true))
                ->setLastSyncedAt(new \DateTimeImmutable())
                ->setUpdatedAt(new \DateTimeImmutable());

            ++$synced;
        }

        $this->entityManager->flush();

        $missing = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel]);
        foreach ($missing as $localInbound) {
            if (!$localInbound instanceof VpnInbound) {
                continue;
            }

            if (!in_array($localInbound->getRemoteInboundId(), $remoteIds, true)) {
                error_log(sprintf(
                    '[PanelSyncInboundsCommand] inbound_missing_on_panel panel_id=%d local_inbound_id=%d remote_inbound_id="%s"',
                    $panelId,
                    $localInbound->getId() ?? 0,
                    $localInbound->getRemoteInboundId()
                ));
            }
        }

        $io->success(sprintf('Synced %d inbound(s).', $synced));

        return Command::SUCCESS;
    }

    private function deriveTitle(array $row, string $fallback): string
    {
        $remark = trim((string) ($row['remark'] ?? ''));
        if ('' !== $remark) {
            return $remark;
        }

        return 'Inbound '.$fallback;
    }

    private function extractTransport(array $row): array
    {
        $network = null;
        $security = null;

        $streamSettings = $row['streamSettings'] ?? null;
        if (is_string($streamSettings) && '' !== trim($streamSettings)) {
            $decoded = json_decode($streamSettings, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $network = $this->nullableText($decoded['network'] ?? null);
                $security = $this->nullableText($decoded['security'] ?? null);
            }
        } elseif (is_array($streamSettings)) {
            $network = $this->nullableText($streamSettings['network'] ?? null);
            $security = $this->nullableText($streamSettings['security'] ?? null);
        }

        return [
            'network' => $network,
            'security' => $security,
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }
}
