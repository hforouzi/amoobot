<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Provisioning\Application\VpnInboundSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:sync-inbound-metadata', description: 'Sync access metadata for a local VpnInbound from panel')]
final class PanelSyncInboundMetadataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnInboundSyncService $vpnInboundSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('inboundId', InputArgument::REQUIRED, 'Local VpnInbound id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inboundId = (int) $input->getArgument('inboundId');
        if ($inboundId <= 0) {
            $io->error('inboundId must be greater than zero.');

            return Command::FAILURE;
        }

        $inbound = $this->entityManager->getRepository(VpnInbound::class)->find($inboundId);
        if (!$inbound instanceof VpnInbound) {
            $io->error('Inbound not found.');

            return Command::FAILURE;
        }

        try {
            $this->vpnInboundSyncService->syncInbound($inbound);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Inbound metadata synced: #%d', $inboundId));

        return Command::SUCCESS;
    }
}

