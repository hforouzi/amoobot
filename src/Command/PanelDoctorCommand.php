<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Application\PanelConnectionReport;
use App\Provisioning\Application\PanelConnectionTester;
use App\Provisioning\Application\PanelSubscriptionUrlCheck;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The operator's check of a real 3x-ui panel after deploy: the same report as the admin "Test connection" button and,
 * with --self-test --yes, a full client lifecycle through the bot's own driver on a temporary client named
 * amoobot_selftest_<random> (create, read, change quota/expiry, disable, enable, batch usage, delete). Other clients
 * are never touched: every write targets that one email, and the clean-up deletes only a client carrying the
 * self-test's random UUID. Clean-up is always attempted, also after a failure. Never prints a secret.
 */
#[AsCommand(name: 'app:panel:doctor', description: 'Check a 3x-ui panel connection (and with --self-test --yes, a full client lifecycle on a temporary client)')]
final class PanelDoctorCommand extends Command
{
    private const GIB = 1073741824;

    /** @var list<string> secrets to strip from anything printed */
    private array $secrets = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PanelConnectionTester $tester,
        private readonly Sanaei3xuiModernDriver $driver,
        private readonly ModernClientsApi $clientsApi,
        private readonly PanelCredentials $credentials,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panelId', InputArgument::REQUIRED, 'VpnPanel id')
            ->addOption('self-test', null, InputOption::VALUE_NONE, 'Create, change and delete a temporary client amoobot_selftest_<random> on the panel')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm --self-test (it writes to the panel)')
            ->addOption('inbound', null, InputOption::VALUE_REQUIRED, 'Remote inbound id for the self-test (default: the first enabled inbound the bot sells from, else the first enabled one)')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Language of the explanations (en or fa)', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locale = in_array($input->getOption('locale'), ['en', 'fa'], true) ? (string) $input->getOption('locale') : 'en';
        $panel = $this->entityManager->find(VpnPanel::class, (int) $input->getArgument('panelId'));
        if (!$panel instanceof VpnPanel || 'sanaei_3xui' !== $panel->getType()) {
            $output->writeln('<error>Panel not found or not a sanaei_3xui panel.</error>');

            return Command::FAILURE;
        }
        if ((bool) $input->getOption('self-test') && !(bool) $input->getOption('yes')) {
            $output->writeln('<error>--self-test creates, changes and deletes a temporary client on the panel. Add --yes to confirm.</error>');

            return Command::INVALID;
        }
        try {
            $this->secrets = array_values(array_filter([$this->credentials->apiToken($panel), $this->credentials->password($panel)]));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$this->safe($e->getMessage()).'</error>');

            return Command::FAILURE;
        }

        $report = $this->tester->test($panel);
        $this->entityManager->flush();
        $this->printReport($output, $panel, $report, $locale);
        if (!$report->isOk()) {
            return Command::FAILURE;
        }
        if (!(bool) $input->getOption('self-test')) {
            return Command::SUCCESS;
        }
        if (!$panel->isModern()) {
            $output->writeln('<error>The self-test runs on the modern API; this panel is in legacy mode.</error>');

            return Command::FAILURE;
        }

        $ok = $this->selfTest($output, $panel, $input->getOption('inbound'));
        $this->entityManager->flush();

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function printReport(OutputInterface $output, VpnPanel $panel, PanelConnectionReport $report, string $locale): void
    {
        $yesNo = static fn (?bool $v): string => null === $v ? '-' : ($v ? 'yes' : 'NO');
        $output->writeln(sprintf('Panel #%d "%s"', $panel->getId() ?? 0, $this->safe($panel->getTitle())));
        $output->writeln(sprintf('  url             %s', $this->safe($report->panelUrl)));
        $output->writeln(sprintf('  token           %s', SecretRedactor::mask($this->secrets[0] ?? null) ?: 'not set'));
        $output->writeln(sprintf('  reachable       %s', $yesNo($report->reachable)));
        $output->writeln(sprintf('  tls             %s', $report->tls));
        $output->writeln(sprintf('  authentication  %s', $yesNo($report->authenticated)));
        $output->writeln(sprintf('  detected mode   %s (integration mode %s, effective %s)', $report->detectedMode->value ?? '-', $panel->getIntegrationMode()->value, $panel->getEffectiveIntegrationMode()->value));
        $output->writeln(sprintf('  latency         %s', null === $report->latencyMs ? '-' : $report->latencyMs.' ms'));
        $output->writeln(sprintf('  inbounds        %s', $report->inboundCount ?? '-'));
        if (null !== $report->failure) {
            $key = 'panel_connection.failure.'.$report->failure->value;
            $output->writeln(sprintf('FAIL  %s: %s', $report->failure->value, $this->translator->trans($key.'.title', [], 'admin', $locale)));
            $output->writeln('      '.$this->translator->trans($key.'.explain', [], 'admin', $locale));
            $output->writeln('      fix: '.$this->translator->trans($key.'.fix', [], 'admin', $locale));
            $output->writeln('      detail: '.$this->safe($report->message));
        } else {
            $output->writeln(sprintf('PASS  connection (%s API)', $report->detectedMode->value ?? '?'));
            if ($panel->isModern()) {
                $this->printHosts($output, $panel);
            }
        }

        // A warning, not a failure: the connection works, but customers would get no usable subscription link.
        $problem = PanelSubscriptionUrlCheck::problem($panel);
        $sample = (string) PanelSubscriptionUrlCheck::sampleUrl($panel);
        if (null === $problem) {
            $output->writeln(sprintf('PASS  subscription URL is publicly usable (%s)', $this->safe($sample)));
        } else {
            $output->writeln('WARN  subscription URL is not publicly usable');
            $output->writeln('      '.$this->safe($this->translator->trans('panel_connection.subscription_check.'.$problem, [
                '%url%' => $sample,
                '%field%' => $this->translator->trans('panel_connection.form.subscription_base_url', [], 'admin', $locale),
                '%section%' => $this->translator->trans('panel_connection.form.advanced', [], 'admin', $locale),
            ], 'admin', $locale)));
        }
    }

    /**
     * Whether the panel has the Hosts API (docs/design/multi-inbound-plans.md 9.4), from GET hosts/list alone: reported,
     * never a switch (customer links come from clients/subLinks either way).
     */
    private function printHosts(OutputInterface $output, VpnPanel $panel): void
    {
        $hosts = $this->clientsApi->hostsCapability($panel);
        $output->writeln(match ($hosts['state']) {
            'yes' => sprintf('INFO  hosts=yes (%d host group(s); customer links follow them through clients/subLinks)', $hosts['count']),
            'no' => 'INFO  hosts=no (this panel has no Hosts API; customer links still come from clients/subLinks)',
            'forbidden' => 'WARN  hosts=forbidden (the API token may not read /panel/api/hosts: give it the admin scope)',
            default => sprintf('WARN  hosts=unknown (%s)', $this->safe((string) $hosts['detail'])),
        });
    }

    private function selfTest(OutputInterface $output, VpnPanel $panel, mixed $inboundOption): bool
    {
        $inbound = $this->selfTestInbound($panel, $inboundOption);
        if (null === $inbound) {
            $output->writeln('FAIL  self-test: no enabled inbound to test on (use --inbound=<remote id>).');

            return false;
        }
        $email = 'amoobot_selftest_'.bin2hex(random_bytes(4));
        $uuid = Uuid::v4()->toRfc4122();
        $output->writeln(sprintf('Self-test on inbound %s with temporary client %s', $inbound->getRemoteInboundId(), $email));

        $remoteId = null;
        $deleted = false;
        $ok = true;
        $step = function (string $name, callable $check) use ($output, &$ok): bool {
            if (!$ok) {
                return false;
            }
            try {
                $detail = $check();
                $output->writeln(sprintf('PASS  %-22s %s', $name, $this->safe((string) $detail)));
            } catch (\Throwable $e) {
                $ok = false;
                $output->writeln(sprintf('FAIL  %-22s %s', $name, $this->safe($e->getMessage())));
            }

            return $ok;
        };

        try {
            $step('name is free', function () use ($panel, $email): string {
                if (null !== $this->clientsApi->getClient($panel, $email)) {
                    throw new \RuntimeException('a client with this name already exists; not touching it');
                }

                return '';
            });
            $step('create', function () use ($panel, $inbound, $email, $uuid, &$remoteId): string {
                $created = $this->driver->createService(new CreateVpnServiceRequest(
                    username: $email, durationDays: 1, trafficLimitGb: 1, ipLimit: 0, inbound: $inbound,
                    remoteInboundId: $inbound->getRemoteInboundId(), meta: [], clientUuid: $uuid,
                ), $panel);
                $remoteId = $created->remoteId;
                $client = $this->mustGet($panel, $email);
                $this->expect(0 === strcasecmp((string) ($client['id'] ?? ''), $uuid), 'the UUID sent is not the one stored');
                $this->expect(self::GIB === (int) ($client['totalGB'] ?? -1), 'totalGB is not 1 GiB in bytes');
                $this->expect(abs((int) ($client['expiryTime'] ?? 0) - (time() + 86400) * 1000) < 120000, 'expiryTime is not now + 1 day in ms');

                return sprintf('%d link(s), subId %s', count($created->configLinks ?? []), '' !== (string) $created->subId ? 'set' : 'MISSING');
            });
            $step('read', function () use ($panel, &$remoteId): string {
                $usage = $this->driver->getUsage((string) $remoteId, $panel);
                $this->expect(self::GIB === $usage->totalBytes, 'clients/traffic total is not 1 GiB');

                return sprintf('used %d bytes, enabled %s', (int) $usage->usedBytes, true === $usage->isEnabled ? 'yes' : 'no');
            });
            $step('update quota/expiry', function () use ($panel, $email, &$remoteId): string {
                $before = $this->mustGet($panel, $email);
                $expires = new \DateTimeImmutable('+2 days');
                $this->driver->renewService((string) $remoteId, new RenewVpnServiceRequest(durationDays: 2, trafficLimitGb: 2, expiresAt: $expires), $panel);
                $after = $this->mustGet($panel, $email);
                $this->expect(2 * self::GIB === (int) ($after['totalGB'] ?? -1), 'totalGB was not changed');
                $this->expect($expires->getTimestamp() * 1000 === (int) ($after['expiryTime'] ?? 0), 'expiryTime was not changed');
                foreach (['id', 'subId', 'comment', 'limitIp', 'flow'] as $field) {
                    $this->expect(($before[$field] ?? null) == ($after[$field] ?? null), sprintf('the update did not keep "%s"', $field));
                }

                return 'full-row update kept id, subId, comment, limitIp, flow';
            });
            $step('disable', function () use ($panel, $email, &$remoteId): string {
                $this->driver->suspendService((string) $remoteId, $panel);
                $this->expect(false === ($this->mustGet($panel, $email)['enable'] ?? null), 'still enabled');

                return '';
            });
            $step('enable', function () use ($panel, $email, &$remoteId): string {
                $this->driver->activateService((string) $remoteId, $panel);
                $this->expect(true === ($this->mustGet($panel, $email)['enable'] ?? null), 'still disabled');

                return '';
            });
            $step('batch usage', function () use ($panel, &$remoteId): string {
                $usages = $this->driver->getUsages($panel, [(string) $remoteId]);
                $this->expect(isset($usages[(string) $remoteId]), 'clients/list does not report the client');
                $this->expect(null !== $usages[(string) $remoteId]->usedBytes, 'clients/list rows carry no traffic');

                return '';
            });
            $step('delete', function () use ($panel, &$remoteId, &$deleted): string {
                $this->driver->deleteService((string) $remoteId, $panel);
                $deleted = true;

                return '';
            });
        } finally {
            if (!$deleted) {
                $output->writeln($this->cleanUp($panel, $email, $uuid));
            }
        }
        $output->writeln($ok ? 'RESULT: OK, the modern API works for selling and managing accounts.' : 'RESULT: FAILED (see the FAIL line above).');

        return $ok;
    }

    /**
     * Deletes the temporary client only if it is still there and carries the self-test's UUID.
     */
    private function cleanUp(VpnPanel $panel, string $email, string $uuid): string
    {
        try {
            $client = $this->clientsApi->getClient($panel, $email);
            if (null === $client) {
                return 'CLEANUP  nothing to remove';
            }
            if (0 !== strcasecmp((string) ($client['id'] ?? ''), $uuid)) {
                return sprintf('CLEANUP  "%s" exists but is not the self-test client: left untouched', $email);
            }
            $this->clientsApi->deleteClient($panel, $email, false);

            return null === $this->clientsApi->getClient($panel, $email) ? sprintf('CLEANUP  removed %s', $email) : sprintf('CLEANUP  FAILED: remove %s on the panel by hand', $email);
        } catch (\Throwable $e) {
            return sprintf('CLEANUP  FAILED (%s): remove %s on the panel by hand', $this->safe($e->getMessage()), $email);
        }
    }

    private function selfTestInbound(VpnPanel $panel, mixed $option): ?VpnInbound
    {
        $options = $this->clientsApi->inboundOptions($panel);
        $enabled = array_values(array_filter($options, static fn (array $o): bool => $o['enable']));
        $remoteId = null;
        if (null !== $option && '' !== (string) $option) {
            foreach ($enabled as $o) {
                if ((string) $o['id'] === (string) $option) {
                    $remoteId = (string) $o['id'];
                }
            }
            if (null === $remoteId) {
                return null;
            }
        }
        $local = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel, 'isActive' => true], ['id' => 'ASC']);
        foreach ($local as $inbound) {
            foreach ($enabled as $o) {
                if ((string) $o['id'] === $inbound->getRemoteInboundId() && (null === $remoteId || $remoteId === $inbound->getRemoteInboundId())) {
                    return $inbound;
                }
            }
        }
        $remoteId ??= isset($enabled[0]) ? (string) $enabled[0]['id'] : null;
        if (null === $remoteId) {
            return null;
        }
        foreach ($enabled as $o) {
            if ((string) $o['id'] === $remoteId) {
                // Not stored: the driver only reads it, nothing is persisted.
                return (new VpnInbound())->setPanel($panel)->setRemoteInboundId($remoteId)->setTitle('self-test')->setProtocol($o['protocol']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mustGet(VpnPanel $panel, string $email): array
    {
        return $this->clientsApi->getClient($panel, $email) ?? throw new \RuntimeException('the client is missing');
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    private function safe(string $text): string
    {
        return SecretRedactor::redact($text, $this->secrets);
    }
}
