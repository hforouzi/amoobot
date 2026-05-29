<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Application\VpnServiceConfigRefreshService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:inbound:regenerate-service-configs', description: 'Regenerate configText and configLinks for all non-deleted services linked to a VpnInbound')]
final class InboundRegenerateServiceConfigsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly VpnServiceConfigRefreshService $configRefreshService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('inboundId', InputArgument::REQUIRED, 'VpnInbound id');
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
            $io->error('VpnInbound not found.');

            return Command::FAILURE;
        }

        $config = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $externalProxyList = $config['externalProxyList'] ?? [];
        $externalProxyCount = is_array($externalProxyList) ? count($externalProxyList) : 0;

        $io->section('Inbound info');
        $io->listing([
            sprintf('inboundId: %d', $inbound->getId() ?? 0),
            sprintf('protocol: %s', (string) ($inbound->getProtocol() ?? '-')),
            sprintf('network: %s', (string) ($inbound->getNetwork() ?? '-')),
            sprintf('externalProxyList count: %d', $externalProxyCount),
        ]);

        /** @var VpnService[] $services */
        $services = $this->entityManager->getRepository(VpnService::class)->findBy(['inbound' => $inbound]);

        $total = count($services);
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($services as $service) {
            if (VpnServiceStatus::DELETED === $service->getStatus()) {
                ++$skipped;
                continue;
            }

            $isLegacySanaei = $this->configRefreshService->isSanaeiLegacyService($service);
            $uuid = trim((string) ($service->getClientUuid() ?? ''));
            $subId = trim((string) ($service->getSubId() ?? ''));
            $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
            if ((!$isLegacySanaei && ('' === $uuid || '' === $subId)) || ($isLegacySanaei && '' === $uuid && '' === $email)) {
                $io->writeln(sprintf('  [skip] service #%d: missing uuid or subId', $service->getId() ?? 0));
                ++$skipped;
                continue;
            }

            try {
                $oldLinks = is_array($service->getConfigLinks()) ? count($service->getConfigLinks()) : 0;
                if ($isLegacySanaei) {
                    $refresh = $this->configRefreshService->refreshSanaeiLegacy($service, 'console_inbound_regenerate_configs');
                    if (!$refresh->succeeded) {
                        $io->writeln(sprintf('  [fail] service #%d: %s', $service->getId() ?? 0, (string) ($refresh->failureReason ?? 'unknown')));
                        ++$failed;
                        continue;
                    }

                    $io->writeln(sprintf(
                        '  [ok] service #%d: %d -> %d links, source=panel',
                        $service->getId() ?? 0,
                        $oldLinks,
                        count($refresh->configLinks)
                    ));

                    ++$updated;
                    continue;
                }

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

                $io->writeln(sprintf(
                    '  [ok] service #%d: %d -> %d links, subUrl=%s',
                    $service->getId() ?? 0,
                    $oldLinks,
                    count($configLinks),
                    $subscriptionUrl ? 'yes' : 'no'
                ));

                ++$updated;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  [fail] service #%d: %s', $service->getId() ?? 0, $e->getMessage()));
                ++$failed;
            }
        }

        $this->entityManager->flush();

        $io->section('Summary');
        $io->listing([
            sprintf('total: %d', $total),
            sprintf('updated: %d', $updated),
            sprintf('skipped: %d', $skipped),
            sprintf('failed: %d', $failed),
        ]);

        $io->success(sprintf('Done. %d service(s) updated.', $updated));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
