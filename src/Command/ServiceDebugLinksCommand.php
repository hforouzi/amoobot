<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:debug-links', description: 'Debug stored access links and parsed remoteId values for a VpnService')]
final class ServiceDebugLinksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
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

        $remoteId = trim((string) ($service->getRemoteId() ?? ''));
        $parsedRemote = $this->remoteIdParser->parse($remoteId);
        $configText = trim((string) ($service->getConfigText() ?? ''));
        $configTextLinkCount = '' === $configText
            ? 0
            : count(array_values(array_filter(array_map('trim', explode("\n", $configText)), static fn (string $line): bool => '' !== $line)));
        $subscriptionUrl = trim((string) ($service->getSubscriptionUrl() ?? ''));
        $qrPossible = '' !== $subscriptionUrl && class_exists(PngWriter::class);

        $io->listing([
            sprintf('service id: %d', $service->getId() ?? 0),
            sprintf('subscriptionUrl: %s', '' !== $subscriptionUrl ? $subscriptionUrl : '(none)'),
            sprintf('configText link count: %d', $configTextLinkCount),
            sprintf('remoteId inbound id: %s', $parsedRemote?->inboundId ?? '-'),
            sprintf('remoteId uuid: %s', $parsedRemote?->clientId ?? '-'),
            sprintf('remoteId email: %s', $parsedRemote?->email ?? '-'),
            sprintf('remoteId subId: %s', $parsedRemote?->subId ?? '-'),
            sprintf('qr can be generated: %s', $qrPossible ? 'yes' : 'no'),
        ]);

        return Command::SUCCESS;
    }
}

