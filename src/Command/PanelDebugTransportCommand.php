<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:debug-transport', description: 'Show resolved panel transport diagnostics')]
final class PanelDebugTransportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
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

        $diagnostics = $this->panelHttpClientFactory->diagnostics($panel);
        $io->listing([
            sprintf('panel id: %s', (string) ($diagnostics['panelId'] ?? '')),
            sprintf('proxy source: %s', (string) ($diagnostics['proxySource'] ?? 'none')),
            sprintf('proxy enabled: %s', (($diagnostics['proxyEnabled'] ?? false) === true) ? 'yes' : 'no'),
            sprintf('proxy type: %s', (string) ($diagnostics['proxyType'] ?? 'none')),
            sprintf('proxy host: %s', (string) ($diagnostics['proxyHost'] ?? '')),
            sprintf('proxy port: %s', (string) ($diagnostics['proxyPort'] ?? '')),
            sprintf('timeout: %s', (string) ($diagnostics['timeout'] ?? '')),
        ]);

        return Command::SUCCESS;
    }
}
