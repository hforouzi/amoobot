<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(name: 'app:panel:test-create-client', description: 'Create a test client on a Sanaei/3x-ui inbound')]
final class PanelTestCreateClientCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
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

        $panel = $inbound->getPanel();
        if ('sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        $driver = $this->driverRegistry->resolve($panel);
        $username = sprintf('test_amoobot_%d', time());
        $trafficGb = 1;
        $durationDays = 1;

        $io->section('Safe diagnostics');
        $io->listing([
            sprintf('panelId: %d', $panel->getId() ?? 0),
            sprintf('localInboundId: %d', $inbound->getId() ?? 0),
            sprintf('remoteInboundId: %s', $inbound->getRemoteInboundId()),
            sprintf('protocol: %s', $inbound->getProtocol() ?? ''),
            sprintf('network: %s', $inbound->getNetwork() ?? ''),
            sprintf('security: %s', $inbound->getSecurity() ?? ''),
            sprintf('username/email: %s', $username),
            sprintf('trafficGb: %d', $trafficGb),
            sprintf('durationDays: %d', $durationDays),
        ]);

        try {
            $created = $driver->createService(new CreateVpnServiceRequest(
                username: $username,
                durationDays: $durationDays,
                trafficLimitGb: $trafficGb,
                ipLimit: 1,
                inbound: $inbound,
                remoteInboundId: $inbound->getRemoteInboundId(),
                meta: ['test' => true],
            ), $panel);
        } catch (\Throwable $e) {
            $io->error(sprintf('Test client creation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success('Test client created successfully.');
        $io->writeln(sprintf('remoteId: %s', $created->remoteId));
        $io->writeln(sprintf('username: %s', $created->username));
        $io->writeln(sprintf('subscriptionUrl: %s', $created->subscriptionUrl ?? 'null'));

        return Command::SUCCESS;
    }
}
