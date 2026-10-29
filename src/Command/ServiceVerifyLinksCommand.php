<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnService;
use App\Provisioning\Application\ServiceErrorText;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ServiceLinkVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:service:verify-links', description: 'Read-only: compare a modern 3x-ui service\'s stored links with the panel\'s subscription (Hosts)')]
final class ServiceVerifyLinksCommand extends Command
{
    /** Assumptions of docs/design/multi-inbound-plans.md 9.6 that need a person in the panel (admin guide, "Verify links"). */
    private const MANUAL = [
        'U1: shuffleHost, isHidden and Nodes have no effect on the links (toggle, refresh, run again)',
        'U3: a REALITY inbound with a host whose security is tls renders as the panel shows',
        'U4: the panel\'s Sub/Web domain is set (the "every address is public" check covers this service only)',
        'U5: the bot\'s API token has the admin scope (app:panel:doctor: hosts=forbidden means it has not)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceLinkVerifier $verifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('serviceId', InputArgument::REQUIRED, 'VpnService id')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
            ->setHelp(<<<'HELP'
                Reads only (the database and GETs to the panel); changes nothing. For a service on a modern 3x-ui panel:

                - the stored link snapshot against the panel's clients/subLinks (normalised: scheme, address, port,
                  security, sni, type, host, path, remark; random SNI/CIDR picks and remark templates tolerated);
                - subLinks against what the panel's Hosts predict (one link per enabled, raw-format host address);
                - every address public, every link carrying this client's credential (VLESS route bytes masked);
                - the stored subId is the panel's (the bot reads subLinks with the stored one);
                - U2: subLinks has no info node; U6: links/{email} gives the same set as subLinks.

                Exit code: <info>0</info> PASS, <info>1</info> differences (listed), <info>2</info> could not check (legacy
                panel, panel unreachable or refusing, no client, or any unexpected error). Links are shown without UUID
                or password, the subId never.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = $this->entityManager->find(VpnService::class, (int) $input->getArgument('serviceId'));
        if (!$service instanceof VpnService) {
            $report = ['exitCode' => 2, 'result' => 'CANNOT CHECK', 'reason' => 'service not found', 'checks' => []];
        } else {
            try {
                $report = $this->verifier->verify($service);
            } catch (\Throwable $e) {
                // Anything unexpected (an odd panel answer, a bug) means "could not run": never exit 1, which reads as DIFF.
                $reason = sprintf('could not run: %s: %s', (new \ReflectionClass($e))->getShortName(), ServiceErrorText::redact($service, $e->getMessage(), 200));
                $report = ['exitCode' => 2, 'result' => 'CANNOT CHECK', 'reason' => $reason, 'checks' => []];
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report + ['manual' => self::MANUAL], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['exitCode'];
        }

        $output->writeln(sprintf('Service #%d: %s%s', (int) $input->getArgument('serviceId'), $report['result'], null !== $report['reason'] ? ' ('.$report['reason'].')' : ''));
        foreach ($report['checks'] as $check) {
            $output->writeln(sprintf('%-5s %s: %s', strtoupper($check['status']), $check['name'], $check['detail']));
            foreach ($check['differences'] as $difference) {
                $output->writeln('      '.$difference);
            }
        }
        if (2 !== $report['exitCode']) {
            $output->writeln('Manual checks (admin guide, "Verify links"):');
            foreach (self::MANUAL as $line) {
                $output->writeln('  - '.$line);
            }
        }

        return $report['exitCode'];
    }
}
