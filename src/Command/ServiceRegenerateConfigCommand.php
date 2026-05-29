<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:regenerate-config', description: 'Regenerate configText/configLinks/subscriptionUrl for an existing VpnService')]
final class ServiceRegenerateConfigCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('serviceId', InputArgument::REQUIRED, 'VpnService id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceId = (int) $input->getArgument('serviceId');
        if ($serviceId <= 0) {
            $io->error('serviceId must be greater than zero.');

            return Command::FAILURE;
        }

        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService) {
            $io->error('VpnService not found.');

            return Command::FAILURE;
        }

        $inbound = $service->getInbound();
        $panel = $service->getPanel();

        $io->section('Service info');
        $io->listing([
            sprintf('serviceId: %d', $service->getId() ?? 0),
            sprintf('username: %s', (string) ($service->getUsername() ?? '-')),
            sprintf('clientUuid: %s', (string) ($service->getClientUuid() ?? '-')),
            sprintf('subId: %s', (string) ($service->getSubId() ?? '-')),
            sprintf('inboundId: %d', $inbound?->getId() ?? 0),
            sprintf('panelId: %d', $panel?->getId() ?? 0),
            sprintf('protocol: %s', (string) ($inbound?->getProtocol() ?? '-')),
            sprintf('network: %s', (string) ($inbound?->getNetwork() ?? '-')),
            sprintf('security: %s', (string) ($inbound?->getSecurity() ?? '-')),
        ]);

        $config = is_array($inbound?->getConfig()) ? $inbound->getConfig() : [];
        $externalProxyList = $config['externalProxyList'] ?? [];
        $remoteRef = $this->remoteIdParser->parse((string) ($service->getRemoteId() ?? ''));
        $currentSubId = trim((string) ($service->getSubId() ?? ''));
        if (null !== $remoteRef && '' === $currentSubId && null !== $remoteRef->subId && '' !== trim($remoteRef->subId)) {
            $service->setSubId(trim($remoteRef->subId));
            $io->writeln(sprintf('subId recovered from remoteId: %s', trim($remoteRef->subId)));
        }
        $io->listing([
            sprintf('externalProxyList count: %d', is_array($externalProxyList) ? count($externalProxyList) : 0),
        ]);

        if ($this->configRefreshService->isSanaeiLegacyService($service)) {
            $refresh = $this->configRefreshService->refreshSanaeiLegacy($service, 'console_service_regenerate_config');
            if (!$refresh->succeeded) {
                $io->error(sprintf('Online panel refresh failed: %s', (string) ($refresh->failureReason ?? 'unknown')));

                return Command::FAILURE;
            }

            $this->entityManager->flush();
            $io->success(sprintf('Service #%d config refreshed from panel. %d link(s) stored.', $serviceId, count($refresh->configLinks)));

            return Command::SUCCESS;
        }

        try {
            $links = $this->vpnAccessLinkGenerator->generate($service);
        } catch (\Throwable $e) {
            $io->error(sprintf('Link generation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $configLinks = array_values(array_filter((array) ($links['configLinks'] ?? []), static fn (mixed $link): bool => '' !== trim((string) $link)));
        $missing = array_values(array_filter((array) ($links['missing'] ?? []), static fn (mixed $item): bool => '' !== trim((string) $item)));
        $subscriptionUrl = $links['subscriptionUrl'] ?? null;
        $finalConfigText = [] !== $configLinks ? implode("\n", $configLinks) : null;

        if ([] !== $missing) {
            $io->warning('Missing fields: '.implode(', ', $missing));
        }

        $io->section('Generated links');
        $io->writeln(sprintf('subscriptionUrl: %s', $subscriptionUrl ?? '(none)'));
        $io->writeln(sprintf('configLinks count: %d', count($configLinks)));
        foreach ($configLinks as $i => $link) {
            $parsed = parse_url($link);
            $host = $parsed['host'] ?? '?';
            $port = $parsed['port'] ?? '?';
            $io->writeln(sprintf('  %d. address=%s port=%s', $i + 1, $host, $port));
        }

        $service
            ->setConfigLinks($configLinks)
            ->setConfigText($finalConfigText)
            ->setSubscriptionUrl($subscriptionUrl ?? $service->getSubscriptionUrl())
            ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $io->success(sprintf('Service #%d config regenerated. %d link(s) stored.', $serviceId, count($configLinks)));

        return Command::SUCCESS;
    }
}
