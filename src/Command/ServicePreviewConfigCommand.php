<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiConfigGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:preview-config', description: 'Preview generated Sanaei configText for an inbound without creating a client')]
final class ServicePreviewConfigCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiConfigGenerator $configGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('inboundId', InputArgument::REQUIRED, 'Inbound id')
            ->addArgument('uuid', InputArgument::REQUIRED, 'Client UUID')
            ->addArgument('subId', InputArgument::REQUIRED, 'Client subId');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $inboundId = (int) $input->getArgument('inboundId');
        $uuid = trim((string) $input->getArgument('uuid'));
        $subId = trim((string) $input->getArgument('subId'));
        if ($inboundId <= 0 || '' === $uuid || '' === $subId) {
            $io->error('Usage: app:service:preview-config {inboundId} {uuid} {subId}');

            return Command::FAILURE;
        }

        $inbound = $this->entityManager->getRepository(VpnInbound::class)->find($inboundId);
        if (!$inbound instanceof VpnInbound) {
            $io->error('Inbound not found.');

            return Command::FAILURE;
        }

        $email = sprintf('usr-%s', $subId);
        $configText = $this->configGenerator->generateConfigText($inbound, $uuid, $email, $subId);
        if ('' === trim($configText)) {
            $io->warning('No config link generated from inbound data.');

            return Command::FAILURE;
        }

        $io->writeln($configText);

        return Command::SUCCESS;
    }
}
