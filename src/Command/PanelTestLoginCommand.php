<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:test-login', description: 'Test login to a Sanaei/3x-ui panel')]
final class PanelTestLoginCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiApiClient $apiClient,
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

        if ('sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        $diagnostics = $this->panelHttpClientFactory->diagnostics($panel);
        $apiVersion = $this->apiClient->getConfiguredApiVersion($panel);
        $authMode = $this->apiClient->getConfiguredAuthMode($panel);
        $bearerEnabled = $this->apiClient->isBearerAuthEnabled($panel);
        $io->section('Transport diagnostics');
        $io->listing([
            sprintf('api_version: %s', $apiVersion),
            sprintf('auth_mode: %s', $authMode),
            sprintf('bearer enabled: %s', $bearerEnabled ? 'yes' : 'no'),
            sprintf('base api url: %s', $this->apiClient->getPanelApiBaseUrl($panel)),
            sprintf('panel id: %s', (string) ($diagnostics['panelId'] ?? '')),
            sprintf('proxy source: %s', (string) ($diagnostics['proxySource'] ?? 'none')),
            sprintf('proxy enabled: %s', (($diagnostics['proxyEnabled'] ?? false) === true) ? 'yes' : 'no'),
            sprintf('proxy type: %s', (string) ($diagnostics['proxyType'] ?? 'none')),
            sprintf('proxy host: %s', (string) ($diagnostics['proxyHost'] ?? '')),
            sprintf('proxy port: %s', (string) ($diagnostics['proxyPort'] ?? '')),
            sprintf('timeout: %s', (string) ($diagnostics['timeout'] ?? '')),
        ]);

        $result = $this->apiClient->login($panel);
        if (($result['ok'] ?? false) !== true) {
            $io->error('Auth/login failed.');

            return Command::FAILURE;
        }

        $io->success('Auth/login successful.');

        $listResult = $this->apiClient->listInbounds($panel);
        if (($listResult['ok'] ?? false) !== true) {
            $io->error(sprintf('List endpoint test failed. status=%s error=%s', (string) ($listResult['status'] ?? 'null'), (string) ($listResult['error'] ?? 'unknown')));

            return Command::FAILURE;
        }

        $payload = is_array($listResult['data'] ?? null) ? $listResult['data'] : [];
        $obj = $payload['obj'] ?? $payload;
        $count = is_array($obj) ? count($obj) : 0;
        $io->success(sprintf('List endpoint test successful. inbound_count=%d', $count));

        return Command::SUCCESS;
    }
}
