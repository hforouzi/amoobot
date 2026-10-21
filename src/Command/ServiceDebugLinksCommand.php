<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\QrCodeImage;
use App\Entity\VpnService;
use App\Provisioning\Application\PanelSubscriptionUrlCheck;
use App\Provisioning\Domain\PublicUrlPolicy;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Where a service's subscription URL and config links point (scheme, host, port, publicly usable or not, known
 * defects) and how its QR code would be sent. Safe to run on the server: the sub id, client UUID and passwords are
 * never printed.
 */
#[AsCommand(name: 'app:service:debug-links', description: 'Debug stored access links and parsed remoteId values for a VpnService')]
final class ServiceDebugLinksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
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

        $parsedRemote = $this->remoteIdParser->parse(trim((string) ($service->getRemoteId() ?? '')));
        $subId = trim((string) ($service->getSubId() ?? $parsedRemote?->subId ?? ''));
        $secrets = array_values(array_filter([$subId, $service->getClientUuid(), $parsedRemote?->clientId], static fn (?string $s): bool => null !== $s && strlen($s) >= 4));

        $output->writeln(sprintf('Service #%d', $service->getId() ?? 0));
        $subscriptionUrl = trim((string) ($service->getSubscriptionUrl() ?? ''));
        $this->line($output, 'subscription URL', '' !== $subscriptionUrl ? $this->hideSubId($subscriptionUrl, $subId) : '(none)');
        if ('' !== $subscriptionUrl) {
            $parts = parse_url($subscriptionUrl) ?: [];
            $this->line($output, 'scheme', (string) ($parts['scheme'] ?? '(none)'), 2);
            $this->line($output, 'host', (string) ($parts['host'] ?? '(none)'), 2);
            $this->line($output, 'port', isset($parts['port']) ? (string) $parts['port'] : '(default)', 2);
            $this->line($output, 'path', $this->hideSubId((string) ($parts['path'] ?? ''), $subId), 2);
            $this->line($output, 'publicly usable', PublicUrlPolicy::isPubliclyUsableUrl($subscriptionUrl) ? 'yes' : 'NO', 2);
            foreach ($this->subscriptionProblems($subscriptionUrl, $subId) as $problem) {
                $this->line($output, 'problem', $problem, 2);
            }
        }
        $panel = $service->getPanel();
        if (null !== $panel) {
            $problem = PanelSubscriptionUrlCheck::problem($panel);
            $this->line($output, 'panel check', null === $problem
                ? 'subscription URL is publicly usable ('.PanelSubscriptionUrlCheck::sampleUrl($panel).')'
                : sprintf('subscription URL is not publicly usable (%s): fill "Subscription base URL" in panel #%d Advanced settings', $problem, $panel->getId() ?? 0));
        }

        $links = array_values(array_filter(array_map(static fn (mixed $link): string => trim((string) $link), (array) ($service->getConfigLinks() ?? [])), static fn (string $link): bool => '' !== $link));
        $this->line($output, 'config links', (string) count($links));
        foreach ($links as $index => $link) {
            $output->writeln(sprintf('    #%-2d %-7s %-40s usable %s', $index + 1, strtolower((string) (parse_url($link, PHP_URL_SCHEME) ?: '?')), $this->hostPort($link), PublicUrlPolicy::isPubliclyUsableLink($link) ? 'yes' : 'NO'));
        }
        $configText = trim((string) ($service->getConfigText() ?? ''));
        $this->line($output, 'configText links', (string) ('' === $configText ? 0 : count(array_filter(array_map('trim', explode("\n", $configText)), static fn (string $line): bool => '' !== $line))));

        $this->line($output, 'remoteId', SecretRedactor::redact(sprintf(
            'inbound %s, uuid %s, email %s, subId %s',
            $parsedRemote?->inboundId ?? '-',
            SecretRedactor::mask($parsedRemote?->clientId) ?: '-',
            $parsedRemote?->email ?? '-',
            SecretRedactor::mask($parsedRemote?->subId) ?: '-',
        ), $secrets));

        $this->line($output, 'QR delivery', $this->qrDelivery($subscriptionUrl, $links));

        return Command::SUCCESS;
    }

    private function line(OutputInterface $output, string $label, string $value, int $indent = 0): void
    {
        $output->writeln(sprintf('  %s%-'.(17 - $indent).'s %s', str_repeat(' ', $indent), $label, $value));
    }

    private function hideSubId(string $text, string $subId): string
    {
        return '' === $subId ? $text : str_replace([$subId, rawurlencode($subId)], '<subId>', $text);
    }

    /**
     * @return list<string>
     */
    private function subscriptionProblems(string $url, string $subId): array
    {
        $problems = [];
        if (!preg_match('#^https?://#i', $url)) {
            $problems[] = 'relative URL: no subscription base URL was set when it was stored';
        }
        if ('' !== $subId && str_contains($url, '/'.$subId.'/'.$subId)) {
            $problems[] = 'the sub id appears twice (stored before the fix; doctrine:migrations:migrate repairs it)';
        } elseif (str_ends_with($url, '/')) {
            $problems[] = 'trailing slash (stored before the fix; doctrine:migrations:migrate repairs it)';
        } elseif ('' !== $subId && !str_ends_with($url, '/'.$subId) && !str_ends_with($url, '/'.rawurlencode($subId))) {
            $problems[] = 'does not end with the sub id';
        }
        if (preg_match('#^https?://#i', $url) && !PublicUrlPolicy::isPubliclyUsableUrl($url)) {
            $problems[] = 'not reachable from a customer\'s phone; it is withheld from customers';
        }
        return $problems;
    }

    private function hostPort(string $link): string
    {
        $host = PublicUrlPolicy::linkHost($link) ?? '(no host)';
        $port = parse_url($link, PHP_URL_PORT);
        if (str_starts_with(strtolower($link), 'vmess://')) {
            $data = json_decode((string) base64_decode(strtr(substr($link, 8), '-_', '+/'), true), true);
            $port = is_array($data) && is_scalar($data['port'] ?? null) ? (int) $data['port'] : null;
        }

        return $host.(is_int($port) && $port > 0 ? ':'.$port : '');
    }

    /**
     * @param list<string> $links
     */
    private function qrDelivery(string $subscriptionUrl, array $links): string
    {
        $usable = PublicUrlPolicy::isPubliclyUsableUrl($subscriptionUrl) ? $subscriptionUrl : null;
        $content = $usable ?? array_values(array_filter($links, [PublicUrlPolicy::class, 'isPubliclyUsableLink']))[0] ?? null;
        if (null === $content) {
            return 'none (nothing usable to encode; the customer is told to contact support)';
        }

        try {
            $size = getimagesizefromstring(QrCodeImage::png($content));
        } catch (\Throwable $e) {
            return 'FAILED to render: '.SecretRedactor::redact($e->getMessage());
        }

        return sprintf(
            '%s of the %s, %d modules, error correction %s, %dpx',
            QrCodeImage::fitsInAPhoto($content) ? 'photo' : 'document (uncompressed)',
            null !== $usable ? 'subscription URL' : 'first config link',
            QrCodeImage::moduleCount($content),
            QrCodeImage::errorCorrection($content)->name,
            false !== $size ? $size[0] : 0,
        );
    }
}
