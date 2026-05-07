<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Application\VpnInboundSyncService;
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
        private readonly VpnInboundSyncService $inboundSyncService,
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

        try {
            $sync = $this->inboundSyncService->syncPanelInbounds($panel);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($sync->missingLocalCount > 0) {
            $io->warning(sprintf('%d local inbound(s) are missing on remote panel.', $sync->missingLocalCount));
        }

        $io->success(sprintf('Synced %d inbound(s).', $sync->syncedCount));

        return Command::SUCCESS;
    }
}
