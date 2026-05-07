<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:panel:test-create-client', description: 'Create a test client on a Sanaei/3x-ui inbound')]
final class PanelTestCreateClientCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiApiClient $apiClient,
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

        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $email = sprintf('test_%d_%d', time(), random_int(1000, 9999));
        $client = [
            'id' => Uuid::v4()->toRfc4122(),
            'flow' => (string) ($config['default_flow'] ?? ''),
            'email' => $email,
            'limitIp' => 0,
            'totalGB' => 5 * 1073741824,
            'expiryTime' => (new \DateTimeImmutable('+3 days'))->getTimestamp() * 1000,
            'enable' => true,
            'tgId' => '',
            'subId' => bin2hex(random_bytes(8)),
            'reset' => 0,
            'security' => (string) ($inbound->getSecurity() ?? 'reality'),
            'network' => (string) ($inbound->getNetwork() ?? 'tcp'),
        ];

        $result = $this->apiClient->addClient($panel, $inbound->getRemoteInboundId(), $client);
        if (($result['ok'] ?? false) !== true) {
            $io->error('Test addClient failed.');

            return Command::FAILURE;
        }

        if (($result['empty'] ?? false) === true) {
            $io->warning(sprintf('Client add request sent and response was empty (email: %s).', $email));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Test client created (email: %s).', $email));

        return Command::SUCCESS;
    }
}
