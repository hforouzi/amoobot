<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:list-inbounds', description: 'List inbounds from a Sanaei/3x-ui panel')]
final class PanelListInboundsCommand extends Command
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
            $io->error('Failed to fetch inbound list.');

            return Command::FAILURE;
        }

        $payload = $result['data'];
        $inbounds = $payload['obj'] ?? $payload;
        if (!is_array($inbounds) || [] === $inbounds) {
            $io->warning('No inbound found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($inbounds as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }

            $rows[] = [
                (string) ($inbound['id'] ?? '-'),
                (string) ($inbound['remark'] ?? '-'),
                (string) ($inbound['port'] ?? '-'),
                (string) ($inbound['protocol'] ?? '-'),
            ];
        }

        $io->table(['id', 'remark', 'port', 'protocol'], $rows);

        return Command::SUCCESS;
    }
}
