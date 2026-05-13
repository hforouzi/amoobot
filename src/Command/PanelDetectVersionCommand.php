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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:detect-version', description: 'Detect Sanaei/3x-ui panel API version/auth recommendation')]
final class PanelDetectVersionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiApiClient $apiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply recommended api_version/auth_mode on panel config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelId = (int) $input->getArgument('panelId');
        $apply = (bool) $input->getOption('apply');

        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($panelId);
        if (!$panel instanceof VpnPanel) {
            $io->error('Panel not found.');

            return Command::FAILURE;
        }

        if ('sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        $originalApiVersion = $panel->getApiVersion();
        $originalAuthMode = $panel->getAuthMode();
        $tokenConfigured = $panel->isApiTokenConfigured();

        $bearerOk = false;
        $legacyOk = false;

        if ($tokenConfigured) {
            $panel->setApiVersion('v3')->setAuthMode('bearer');
            $bearerResult = $this->apiClient->listInbounds($panel);
            $bearerOk = ($bearerResult['ok'] ?? false) === true;
            $io->writeln(sprintf('v3 bearer probe: %s (status=%s error=%s)', $bearerOk ? 'ok' : 'failed', (string) ($bearerResult['status'] ?? 'null'), (string) ($bearerResult['error'] ?? '')));
        } else {
            $io->writeln('v3 bearer probe: skipped (no api token configured)');
        }

        $panel->setApiVersion('legacy')->setAuthMode('cookie');
        $this->apiClient->login($panel);
        $legacyResult = $this->apiClient->listInbounds($panel);
        $legacyOk = ($legacyResult['ok'] ?? false) === true;
        $io->writeln(sprintf('legacy cookie probe: %s (status=%s error=%s)', $legacyOk ? 'ok' : 'failed', (string) ($legacyResult['status'] ?? 'null'), (string) ($legacyResult['error'] ?? '')));

        $recommendedApiVersion = $bearerOk ? 'v3' : 'legacy';
        $recommendedAuthMode = $bearerOk ? 'bearer' : 'cookie';

        if ($apply) {
            $panel
                ->setApiVersion($recommendedApiVersion)
                ->setAuthMode($recommendedAuthMode)
                ->setLastTestResult('OK', sprintf('detect-version applied: %s/%s', $recommendedApiVersion, $recommendedAuthMode));
            $this->entityManager->flush();
            $io->success(sprintf('Applied recommendation: api_version=%s auth_mode=%s', $recommendedApiVersion, $recommendedAuthMode));

            return Command::SUCCESS;
        }

        $panel->setApiVersion($originalApiVersion)->setAuthMode($originalAuthMode);
        $io->success(sprintf('Recommendation: api_version=%s auth_mode=%s', $recommendedApiVersion, $recommendedAuthMode));
        $io->note('Use --apply to save recommendation in panel config.');

        return Command::SUCCESS;
    }
}
