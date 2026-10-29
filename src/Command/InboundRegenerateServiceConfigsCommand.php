<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Provisioning\Application\ServiceLinkChange;
use App\Provisioning\Application\ServiceLinkRegenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:inbound:regenerate-service-configs', description: 'Re-read or regenerate the config links of every service of a VpnInbound (dry run unless --apply)')]
final class InboundRegenerateServiceConfigsCommand extends Command
{
    use RegeneratesLinksInBatches;

    public const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceLinkRegenerator $linkRegenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('inboundId', InputArgument::REQUIRED, 'VpnInbound id')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Store the new links (without it nothing is written)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show old vs new links without writing anything (the default)')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Resume: only services with an id greater than this', '0')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Services read per batch (each service is written on its own)', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between batches (spares the panel)', self::DEFAULT_PAUSE_SECONDS)
            ->setHelp(<<<'HELP'
                For every service of the inbound (deleted ones skipped), in order of id:

                - modern 3x-ui panel: exactly the panel's own links (clients/subLinks, Hosts applied); a service whose
                  panel call fails or gives no links keeps its stored links and is counted as failed;
                - legacy 3x-ui panel: re-read through the legacy driver, as before;
                - other panels: built from the inbound, as before.

                <comment>A dry run is the default for every service</comment>: it prints, per service, old vs new link
                count and the links that would be added (+) or removed (-), and writes nothing. Pass <info>--apply</info>
                to store. Each service is written on its own (row locked, credentials re-checked; links read for
                credentials a reset changed meanwhile are never written). Services are read in batches
                (<info>--batch-size</info>) with a pause between them (<info>--pause</info>, seconds); after each batch the
                last processed id is printed: resume an interrupted run with <info>--after-id=<id></info>.

                The run stops after 5 services in a row failed (the panel is probably down). A bulk run never marks the
                panel "degraded": its failed calls are not counted towards that.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = $this->applyRequested($input, $io);
        if (null === $apply) {
            return Command::INVALID;
        }
        $inboundId = (int) $input->getArgument('inboundId');
        $afterId = max(0, (int) $input->getOption('after-id'));
        $batchSize = max(1, min(500, (int) $input->getOption('batch-size')));
        if ($inboundId <= 0) {
            $io->error('inboundId must be greater than zero.');

            return Command::FAILURE;
        }
        $inbound = $this->entityManager->getRepository(VpnInbound::class)->find($inboundId);
        if (!$inbound instanceof VpnInbound) {
            $io->error('VpnInbound not found.');

            return Command::FAILURE;
        }
        $io->section(sprintf('Inbound #%d (%s), %s', $inboundId, (string) ($inbound->getProtocol() ?? '-'), $apply ? 'APPLY (writes)' : 'dry run (nothing is written)'));

        $run = $this->regenerateInBatches($io, [$inboundId], 'console_inbound_regenerate_configs', $apply, $afterId, $batchSize, self::pauseMs($input->getOption('pause')));
        $counts = $run['counts'];
        $this->printSummary($io, $counts, $apply);
        if ($run['stopped']) {
            return Command::FAILURE;
        }
        if ($apply) {
            $io->success('Done.');
        } else {
            $io->success('Dry run: nothing was written. Run again with --apply to store the new links.');
        }

        return 0 === $counts[ServiceLinkChange::FAILED] + $counts[ServiceLinkChange::KEPT] + $counts[ServiceLinkChange::CHANGED] ? Command::SUCCESS : Command::FAILURE;
    }
}
