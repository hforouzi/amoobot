<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\SanaeiCapabilityProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:panel:detect-version', description: 'Probe a 3x-ui panel: modern (API token + clients API) or legacy (cookie login)')]
final class PanelDetectVersionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SanaeiCapabilityProbe $probe,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Store the detected mode and probe time on the panel (used when its integration mode is "auto")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panel = $this->entityManager->getRepository(VpnPanel::class)->find((int) $input->getArgument('panelId'));
        if (!$panel instanceof VpnPanel || 'sanaei_3xui' !== $panel->getType()) {
            $io->error('Panel not found or not a sanaei_3xui panel.');

            return Command::FAILURE;
        }

        $report = (bool) $input->getOption('apply') ? $this->probe->probeAndRecord($panel) : $this->probe->probe($panel);
        $io->writeln(sprintf('detected: %s', $report->detected->value ?? 'none'));
        $io->writeln(sprintf('status:   %s', $report->status()));
        $io->writeln(sprintf('detail:   %s', $report->message));
        $io->writeln(sprintf('integration mode: %s (effective: %s)', $panel->getIntegrationMode()->value, $panel->getEffectiveIntegrationMode()->value));

        if ((bool) $input->getOption('apply')) {
            $this->entityManager->flush();
            $io->success('Probe result stored.');
        } else {
            $io->note('Use --apply to store the detected mode on the panel.');
        }

        return null === $report->detected ? Command::FAILURE : Command::SUCCESS;
    }
}
