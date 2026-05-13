<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:test-client-links', description: 'Test official client links endpoint for a VpnService on Sanaei/3x-ui')]
final class PanelTestClientLinksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly Sanaei3xuiApiClient $apiClient,
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

        $panel = $service->getPanel();
        if (null === $panel || 'sanaei_3xui' !== $panel->getType()) {
            $io->error('Service panel type must be sanaei_3xui.');

            return Command::FAILURE;
        }

        if ('v3' !== $this->apiClient->getConfiguredApiVersion($panel)) {
            $io->warning('Panel api_version is not v3; official getClientLinks endpoint is v3-only.');

            return Command::SUCCESS;
        }

        $remote = $this->remoteIdParser->parse((string) ($service->getRemoteId() ?? ''));
        if (null === $remote) {
            $io->error('Unable to parse service remoteId.');

            return Command::FAILURE;
        }

        if (!preg_match('/^\d+$/', trim($remote->inboundId))) {
            $io->error('Parsed inbound id is not numeric.');

            return Command::FAILURE;
        }

        $result = $this->apiClient->getClientLinks($panel, (int) $remote->inboundId, $remote->email);
        if (($result['ok'] ?? false) !== true) {
            $io->error(sprintf('getClientLinks failed. status=%s error=%s', (string) ($result['status'] ?? 'null'), (string) ($result['error'] ?? 'unknown')));

            return Command::FAILURE;
        }

        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $obj = $payload['obj'] ?? null;
        $links = [];
        if (is_array($obj)) {
            foreach ($obj as $item) {
                $link = trim((string) $item);
                if ('' !== $link) {
                    $links[] = $link;
                }
            }
        }

        $io->success(sprintf('getClientLinks success. link_count=%d', count($links)));
        $preview = array_slice($links, 0, 3);
        if ([] !== $preview) {
            $io->writeln('Sanitized preview:');
            foreach ($preview as $line) {
                $io->writeln('- '.$this->sanitizeLink($line));
            }
        }

        return Command::SUCCESS;
    }

    private function sanitizeLink(string $link): string
    {
        $text = trim($link);
        $text = preg_replace('#(://)[^@/\s]+@#', '$1[redacted]@', $text) ?? $text;

        return mb_substr($text, 0, 200);
    }
}
