<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceLinkChange;
use App\Provisioning\Application\ServiceLinkRegenerator;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:service:regenerate-config', description: 'Re-read or regenerate the config links of one VpnService (dry run unless --apply)')]
final class ServiceRegenerateConfigCommand extends Command
{
    use LinkChangeOutput;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly ServiceLinkRegenerator $linkRegenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('serviceId', InputArgument::REQUIRED, 'VpnService id')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Store the new links (without it nothing is written)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show old vs new links without writing anything (the default)')
            ->setHelp(<<<'HELP'
                Shows, and with <info>--apply</info> stores, the service's config links:

                - modern 3x-ui panel: exactly the panel's own links (clients/subLinks, Hosts applied). If the panel
                  cannot be asked or gives no links, the stored links are kept and the command fails;
                - legacy 3x-ui panel: re-read through the legacy driver, as before;
                - other panels: built from the inbound, as before.

                <comment>A dry run is the default</comment>: it prints old vs new link count and every link that would be
                added (+) or removed (-), and writes nothing. Links are shown as scheme, address:port and remark only.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = $this->applyRequested($input, $io);
        if (null === $apply) {
            return Command::INVALID;
        }
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
            sprintf('inboundId: %d', $inbound?->getId() ?? 0),
            sprintf('panelId: %d', $panel?->getId() ?? 0),
            sprintf('protocol: %s', (string) ($inbound?->getProtocol() ?? '-')),
            sprintf('mode: %s', $apply ? 'APPLY (writes)' : 'dry run (nothing is written)'),
        ]);

        if ($apply) {
            $remoteRef = $this->remoteIdParser->parse((string) ($service->getRemoteId() ?? ''));
            if (null !== $remoteRef && '' === trim((string) ($service->getSubId() ?? '')) && null !== $remoteRef->subId && '' !== trim($remoteRef->subId)) {
                $service->setSubId(trim($remoteRef->subId));
                $io->writeln('subId recovered from remoteId.');
            }
        }

        $change = $this->linkRegenerator->run($service, 'console_service_regenerate_config', $apply);
        $this->printChange($io, $serviceId, $change, $apply);

        if (in_array($change->outcome, [ServiceLinkChange::FAILED, ServiceLinkChange::KEPT, ServiceLinkChange::SKIPPED], true)) {
            $io->error(sprintf('Service #%d: links not changed (%s).', $serviceId, $change->error ?? $change->outcome));

            return Command::FAILURE;
        }
        if (!$apply) {
            $io->success(sprintf('Dry run: service #%d would have %d link(s). Nothing was written; run again with --apply to store them.', $serviceId, count($change->after)));

            return Command::SUCCESS;
        }
        $this->entityManager->flush();
        $io->success(sprintf('Service #%d: %d link(s) stored (source: %s).', $serviceId, count($change->after), $change->source));

        return Command::SUCCESS;
    }
}
