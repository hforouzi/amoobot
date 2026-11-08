<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Application\PanelClientImporter;
use App\Provisioning\Application\PanelClientImportSummary;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * docs/design/panel-client-import.md. Prints counts only: never a Telegram id, UUID, email, subId or link, also when it
 * fails (an unexpected error is reported by its class, not its message, which may quote a value).
 */
#[AsCommand(name: 'app:panel:import-clients', description: 'Import the clients of a 3x-ui panel (modern mode) as services of their Telegram owners')]
final class PanelImportClientsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelClientImporter $importer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('panel', null, InputOption::VALUE_REQUIRED, 'VpnPanel id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read the panel and count, write nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many services were imported')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Clients read and written per batch (1-200)', (string) PanelClientImporter::DEFAULT_BATCH_SIZE)
            ->setHelp(<<<'HELP'
                Creates a service for every client of the panel whose tgId names a Telegram user, owned by that user's
                account (created when there is none). Nothing is changed on the panel and no order, payment or
                notification is created. Imported services have no plan: renewal and extra traffic ask the customer to
                buy a new plan. A second run imports nothing new. Run app:panel:sync-inbounds first.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelId = (int) $input->getOption('panel');
        $limit = null === $input->getOption('limit') ? null : (int) $input->getOption('limit');
        if ($panelId <= 0 || (null !== $limit && $limit <= 0)) {
            $io->error('Give --panel=ID (and --limit as a positive number).');

            return Command::INVALID;
        }
        $panel = $this->entityManager->getRepository(VpnPanel::class)->find($panelId);
        if (!$panel instanceof VpnPanel) {
            $io->error('Panel not found.');

            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $summary = $this->importer->import($panel, $dryRun, $limit, (int) $input->getOption('batch-size'));
        } catch (\Throwable $e) {
            $this->logger->error('panel_client_import_failed', ['panel_id' => $panelId, 'error' => $e::class]);
            $io->error(sprintf('The import stopped with an unexpected error (%s). The batch it was writing was rolled back; batches before it are kept. Run the command again to continue.', (new \ReflectionClass($e))->getShortName()));

            return Command::FAILURE;
        }

        if (PanelClientImportSummary::STOPPED_NOT_MODERN === $summary->stopped) {
            $io->error('This panel is not a 3x-ui panel in modern mode: the legacy API cannot list clients with their Telegram id (tgId). Nothing was imported. Set an API token and test the connection (Panels), then run again.');

            return Command::FAILURE;
        }

        $io->title($dryRun ? 'Panel client import: dry run (nothing written)' : 'Panel client import');
        $io->listing([
            sprintf('Clients read: %d', $summary->read),
            sprintf('%s: %d', $dryRun ? 'Would import' : 'Imported', $summary->imported),
            sprintf('New users %s: %d', $dryRun ? 'to create' : 'created', $summary->newUsers),
            sprintf('Existing users matched: %d', $summary->existingUsers),
            sprintf('Already known: %d', $summary->alreadyKnown),
            sprintf('No owner (no tgId): %d', $summary->noOwner),
            sprintf('Unknown inbound: %d', $summary->unknownInbound),
            sprintf('Invalid: %d', $summary->invalid),
            sprintf('Most services for one owner: %d', $summary->mostForOneOwner),
        ]);

        $code = Command::SUCCESS;
        if ($summary->mostForOneOwner > PanelClientImportSummary::MANY_FOR_ONE_OWNER) {
            $io->warning(sprintf('One Telegram account would own %d services: check that the panel\'s tgId values are the customers\' and not an admin\'s.', $summary->mostForOneOwner));
        }
        if ($summary->unknownInbound > 0) {
            $io->warning(sprintf('%d client(s) are on inbounds the bot does not know: run app:panel:sync-inbounds %d, then run this import again.', $summary->unknownInbound, $panelId));
        }
        if (PanelClientImportSummary::STOPPED_PANEL_READ === $summary->stopped) {
            $io->error(sprintf('Reading the panel failed (%s). Nothing of the batch being read was written; the counts above are what was done before. Run the command again to continue.', $summary->failure));
            $code = Command::FAILURE;
        } elseif (!$summary->tgIdReported && $summary->noOwner > 0 && 0 === $summary->imported) {
            $io->error('The panel did not return a tgId for any client: it cannot tell who owns them. Nothing was imported.');
            $code = Command::FAILURE;
        } elseif ($summary->limitReached) {
            $io->note('Stopped at --limit. Run again to continue.');
        }
        if (Command::SUCCESS === $code) {
            $io->success($dryRun ? 'Dry run finished: nothing was written.' : 'Import finished.');
        }

        return $code;
    }
}
