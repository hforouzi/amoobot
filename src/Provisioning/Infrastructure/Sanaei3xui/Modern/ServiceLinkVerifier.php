<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Application\PanelLinkSnapshot;
use App\Provisioning\Application\ServiceErrorText;
use App\Provisioning\Domain\PublicUrlPolicy;
use App\Provisioning\Domain\ShareLinkTuple;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The read-only check behind app:service:verify-links (docs/design/multi-inbound-plans.md 9.6): it compares a modern
 * 3x-ui service's stored link snapshot with the panel's clients/subLinks, and subLinks with what the panel's Hosts
 * predict (9.2), and checks addresses, credentials and the assumptions U2 and U6. It only reads, from the database and
 * the panel (GETs); nothing is written anywhere.
 *
 * The report's checks have a status: pass, diff (a difference, listed), skip (not applicable here, with the reason) or
 * info. Exit code: 0 = no diff, 1 = at least one diff, 2 = the check could not run.
 *
 * A service with additional inbounds (vpn_service_inbound rows, plans with several inbounds) gets one more check: the
 * client is on the primary and on every inbound recorded as attached, so their links are expected in subLinks (the
 * Hosts prediction covers every inbound the client is on). A failed or pending attachment is reported, not a
 * difference: its links are simply not expected yet.
 */
final class ServiceLinkVerifier
{
    public const PASS = 'pass';
    public const DIFF = 'diff';
    public const SKIP = 'skip';
    public const INFO = 'info';

    public function __construct(
        private readonly ModernClientsApi $api,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{exitCode: int, result: string, reason: ?string, checks: list<array{name: string, status: string, detail: string, differences: list<string>}>}
     */
    public function verify(VpnService $service): array
    {
        $panel = $service->getPanel();
        if (null === $panel || 'sanaei_3xui' !== $panel->getType()) {
            return self::cannot('not a 3x-ui service');
        }
        if (!$panel->isModern()) {
            return self::cannot('legacy: Hosts not applicable (the panel is in legacy mode)');
        }
        $ref = $this->remoteIdParser->parse((string) $service->getRemoteId());
        if (null === $ref || '' === $ref->email) {
            return self::cannot('the service has no client on the panel (remote id unreadable)');
        }

        try {
            // The probe first: on an old panel it is a 404, and the successful reads after it clear the failure count.
            $hosts = $this->api->hostsCapability($panel);
            $client = $this->api->getClient($panel, $ref->email);
            if (null === $client) {
                return self::cannot('the client does not exist on the panel');
            }
            $subId = trim((string) ($client['subId'] ?? ''));
            if ('' === $subId) {
                return self::cannot('the client has no subId on the panel');
            }
            $sub = $this->api->subLinks($panel, $subId);
            $own = $this->api->links($panel, $ref->email);
            $inboundIds = array_values(array_map('intval', (array) ($client['inboundIds'] ?? [])));
            $options = [];
            foreach ($this->api->inboundOptions($panel) as $option) {
                $options[$option['id']] = $option;
            }
            $groups = [];
            if ('yes' === $hosts['state']) {
                foreach ($inboundIds as $inboundId) {
                    $groups[$inboundId] = $this->api->hostsByInbound($panel, $inboundId);
                }
            }
        } catch (PanelApiException $e) {
            return self::cannot('the panel could not be read: '.ServiceErrorText::redact($service, $e->getMessage()));
        }

        $panelLinks = self::tuples($sub['links']);
        $tolerance = self::tolerance($groups);
        $bindings = array_values(array_filter(array_map('intval', (array) ($client['clientHostRuleIds'] ?? [])), static fn (int $id): bool => $id > 0));

        $checks = [];
        $checks[] = ['name' => 'panel', 'status' => self::INFO, 'detail' => sprintf('hosts=%s; client on inbound(s) %s; %d link(s) in subLinks; per-client host binding: %s; link order is not compared',
            $hosts['state'], [] === $inboundIds ? '-' : implode(', ', $inboundIds), count($sub['links']), [] === $bindings ? 'none' : implode(', ', $bindings)), 'differences' => []];
        $checks[] = self::storedSubId($service, $subId);
        $rows = null === $service->getId() ? [] : $this->entityManager->getRepository(VpnServiceInbound::class)->findBy(['service' => $service], ['id' => 'ASC']);
        if ([] !== $rows) {
            $checks[] = self::attachedInbounds($service, (string) $ref->inboundId, $rows, $inboundIds);
        }
        $checks[] = $this->storedVsPanel($service, $panelLinks, $tolerance);
        $checks[] = $this->prediction($hosts['state'], $groups, $options, $inboundIds, $bindings, $panelLinks);
        $checks[] = self::publicAddresses($panelLinks);
        $checks[] = self::credentials($panelLinks, $client);
        $checks[] = self::infoNode($sub);
        $checks[] = $this->clientLinksVsSubLinks(self::tuples($own['links']), $panelLinks, $tolerance, count($inboundIds));

        $diff = [] !== array_filter($checks, static fn (array $c): bool => self::DIFF === $c['status']);

        return ['exitCode' => $diff ? 1 : 0, 'result' => $diff ? 'DIFF' : 'PASS', 'reason' => null, 'checks' => $checks];
    }

    /**
     * The subId the bot reads subLinks with (the stored one, else the remote id's) is the panel's: otherwise the bot
     * refreshes a subscription that is not this client's, or none. Neither value is shown (a subId opens the
     * subscription).
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private static function storedSubId(VpnService $service, string $panelSubId): array
    {
        $stored = trim((string) $service->getSubId());
        $used = PanelLinkSnapshot::effectiveSubId($service);
        $differences = [];
        if ($used !== $panelSubId) {
            $differences[] = '' === $stored && null === $used
                ? 'no subId is stored and the remote id has none: the bot reads links/{email} instead of subLinks'
                : 'the subId the bot uses differs from the panel\'s: its links are not this client\'s subscription (re-sync the service)';
        } elseif ('' === $stored) {
            $differences[] = 'no subId is stored (the remote id\'s is used): app:service:regenerate-config <id> --apply stores it';
        }

        return ['name' => 'stored subId = panel subId', 'status' => [] === $differences ? self::PASS : self::DIFF, 'detail' => 'compared, not shown', 'differences' => $differences];
    }

    /**
     * Several inbounds: the primary and every row recorded as attached must be on the client's inboundIds (their links
     * are expected); failed and pending rows are listed for information only.
     *
     * @param list<VpnServiceInbound> $rows
     * @param list<int>               $inboundIds the client's, as the panel reports them
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private static function attachedInbounds(VpnService $service, string $remotePrimary, array $rows, array $inboundIds): array
    {
        $primary = (int) ($service->getInbound()?->getRemoteInboundId() ?? $remotePrimary);
        $expected = [$primary];
        $differences = [];
        $notes = [];
        foreach ($rows as $row) {
            $remote = (int) $row->getRemoteInboundId();
            $onPanel = in_array($remote, $inboundIds, true);
            if (VpnServiceInbound::STATUS_ATTACHED === $row->getStatus()) {
                $expected[] = $remote;
            } elseif (VpnServiceInbound::STATUS_DETACHED !== $row->getStatus()) {
                $notes[] = sprintf('inbound %d: %s after %d attempt(s)%s, its links are not expected', $remote, $row->getStatus(), $row->getAttempts(), $onPanel ? ' (the client is on it on the panel; "Retry attach" records it)' : '');
            }
        }
        foreach ($expected as $remote) {
            if (!in_array($remote, $inboundIds, true)) {
                $differences[] = sprintf('inbound %d is recorded as attached but the client is not on it on the panel: its links are missing', $remote);
            }
        }

        return [
            'name' => 'client on every attached inbound',
            'status' => [] !== $differences ? self::DIFF : ([] !== $notes ? self::INFO : self::PASS),
            'detail' => sprintf('expected on inbound(s) %s%s', implode(', ', $expected), [] === $notes ? '' : '; '.implode('; ', $notes)),
            'differences' => $differences,
        ];
    }

    /**
     * Step 4: the stored snapshot and subLinks, as multisets of tuples.
     *
     * @param list<ShareLinkTuple|string>                                        $panelLinks
     * @param array{poolAddresses: list<string>, cidr: bool, known: list<string>, template: bool} $tolerance
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private function storedVsPanel(VpnService $service, array $panelLinks, array $tolerance): array
    {
        $stored = self::tuples(array_values(array_filter(array_map(static fn (mixed $l): string => trim((string) $l), (array) ($service->getConfigLinks() ?? [])), static fn (string $l): bool => '' !== $l)));
        $differences = self::multisetDifferences($stored, $panelLinks, $tolerance, 'only in the stored snapshot', 'only in the panel\'s subLinks');

        return [
            'name' => 'stored snapshot = panel subLinks',
            'status' => [] === $differences ? self::PASS : self::DIFF,
            'detail' => sprintf('%d stored, %d on the panel%s', count($stored), count($panelLinks), [] === $differences ? '' : ' (refresh with app:service:regenerate-config <id> --apply)'),
            'differences' => $differences,
        ];
    }

    /**
     * Step 5: subLinks against the hosts of each inbound (9.2): count, and address/port/security/sni of every host link.
     *
     * @param array<int, list<array<string, mixed>>>                       $groups
     * @param array<int, array{id: int, port: int, remark: string}>        $options
     * @param list<int>                                                    $inboundIds
     * @param list<int>                                                    $bindings
     * @param list<ShareLinkTuple|string>                                  $panelLinks
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private function prediction(string $hostsState, array $groups, array $options, array $inboundIds, array $bindings, array $panelLinks): array
    {
        $name = 'panel subLinks = Hosts prediction';
        if ('yes' !== $hostsState) {
            return ['name' => $name, 'status' => self::SKIP, 'detail' => sprintf('hosts=%s: no host data to predict from', $hostsState), 'differences' => []];
        }
        if ([] !== $bindings) {
            return ['name' => $name, 'status' => self::SKIP, 'detail' => 'the client is bound to host rules (clientHostRuleIds): compare by hand in the panel', 'differences' => []];
        }
        $differences = [];
        $exact = true;
        $expectedTotal = 0;
        foreach ($inboundIds as $inboundId) {
            $inboundPort = (int) ($options[$inboundId]['port'] ?? 0);
            $usable = 0;
            foreach ($groups[$inboundId] ?? [] as $group) {
                if ((bool) ($group['isDisabled'] ?? false) || in_array('raw', (array) ($group['excludeFromSubTypes'] ?? []), true)) {
                    continue;
                }
                $snis = array_values(array_filter(array_map('strval', (array) ($group['snis'] ?? [])), static fn (string $s): bool => '' !== $s));
                foreach ((array) ($group['hosts'] ?? []) as $entry) {
                    [$address, $port] = self::addressAndPort((string) $entry, (int) ($group['port'] ?? 0), $inboundPort);
                    if (str_contains($address, '/')) {
                        $usable += max(1, (int) ($group['randomCount'] ?? 1));
                        continue;
                    }
                    ++$usable;
                    $match = array_values(array_filter($panelLinks, static fn (mixed $t): bool => $t instanceof ShareLinkTuple && $t->address === $address && $t->port === $port));
                    if ([] === $match) {
                        $differences[] = sprintf('inbound %d: no link for host "%s" at %s:%d', $inboundId, (string) ($group['remark'] ?? ''), $address, $port);
                        continue;
                    }
                    $security = (string) ($group['security'] ?? 'same');
                    if (!in_array($security, ['', 'same'], true) && $match[0]->security !== $security) {
                        $differences[] = sprintf('inbound %d, %s:%d: security %s, the host says %s', $inboundId, $address, $port, $match[0]->security, $security);
                    }
                    $sni = (bool) ($group['overrideSniFromAddress'] ?? false) ? $address : (string) ($group['sni'] ?? '');
                    if (!(bool) ($group['keepSniBlank'] ?? false) && count($snis) <= 1 && '' !== $sni && $match[0]->sni !== $sni) {
                        $differences[] = sprintf('inbound %d, %s:%d: sni "%s", the host says "%s"', $inboundId, $address, $port, $match[0]->sni, $sni);
                    }
                    foreach (['path' => 'path', 'hostHeader' => 'host'] as $field => $property) {
                        $wanted = (string) ($group[$field] ?? '');
                        if ('' !== $wanted && '' !== $match[0]->{$property} && $match[0]->{$property} !== $wanted) {
                            $differences[] = sprintf('inbound %d, %s:%d: %s "%s", the host says "%s"', $inboundId, $address, $port, $property, $match[0]->{$property}, $wanted);
                        }
                    }
                }
            }
            if (0 === $usable) {
                // The legacy path: one link, or one per legacy externalProxy entry the API does not show.
                $exact = false;
                $usable = 1;
            }
            $expectedTotal += $usable;
        }
        $actual = count($panelLinks);
        if (($exact && $actual !== $expectedTotal) || (!$exact && $actual < $expectedTotal)) {
            $differences[] = sprintf('subLinks has %d link(s), the hosts predict %s%d', $actual, $exact ? '' : 'at least ', $expectedTotal);
        }

        return [
            'name' => $name,
            'status' => [] === $differences ? self::PASS : self::DIFF,
            'detail' => sprintf('%s%d link(s) predicted, %d given', $exact ? '' : 'at least ', $expectedTotal, $actual),
            'differences' => $differences,
        ];
    }

    /**
     * @param list<ShareLinkTuple|string> $links
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private static function publicAddresses(array $links): array
    {
        $differences = [];
        foreach ($links as $link) {
            if ($link instanceof ShareLinkTuple && !PublicUrlPolicy::isPublicHost($link->address)) {
                $differences[] = sprintf('not reachable from the internet: %s (set the panel\'s Sub/Web domain or a host)', $link->describe());
            }
        }

        return ['name' => 'every address is public', 'status' => [] === $differences ? self::PASS : self::DIFF, 'detail' => sprintf('%d link(s) checked', count($links)), 'differences' => $differences];
    }

    /**
     * Every link carries this client's credential (a VLESS route may change UUID bytes 6-7 only).
     *
     * @param list<ShareLinkTuple|string> $links
     * @param array<string, mixed>        $client
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private static function credentials(array $links, array $client): array
    {
        $uuid = ShareLinkTuple::maskedUuid((string) ($client['id'] ?? ''));
        $password = (string) ($client['password'] ?? '');
        $differences = [];
        $checked = 0;
        foreach ($links as $link) {
            if (!$link instanceof ShareLinkTuple) {
                $differences[] = 'a link could not be parsed: '.$link;
                continue;
            }
            $ok = match ($link->scheme) {
                'vless', 'vmess', 'tuic' => ShareLinkTuple::maskedUuid($link->credential) === $uuid,
                'trojan', 'hysteria2', 'hy2' => '' === $password || $link->credential === $password,
                'ss' => '' === $password || str_contains($link->credential, $password),
                default => null,
            };
            if (null === $ok) {
                continue;
            }
            ++$checked;
            if (!$ok) {
                $differences[] = 'credential of another client: '.$link->describe();
            }
        }

        return ['name' => 'every link carries this client\'s credential', 'status' => [] === $differences ? self::PASS : self::DIFF, 'detail' => sprintf('%d link(s) checked (VLESS route bytes masked)', $checked), 'differences' => $differences];
    }

    /**
     * U2: the API's subLinks never carries the subscription "info node" (a socks://127.0.0.1 line) or anything else the
     * bot would have to drop.
     *
     * @param array{links: list<string>, rejected: int} $sub
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private static function infoNode(array $sub): array
    {
        $differences = $sub['rejected'] > 0 ? [sprintf('%d item(s) of subLinks are not usable share links (info node, other scheme or malformed); the bot drops them', $sub['rejected'])] : [];

        return ['name' => 'U2: subLinks has no info node or unusable item', 'status' => [] === $differences ? self::PASS : self::DIFF, 'detail' => 'automatic', 'differences' => $differences];
    }

    /**
     * U6 (and step 6 duplicates): links/{email} and subLinks give the same set.
     *
     * @param list<ShareLinkTuple|string>                                                     $own
     * @param list<ShareLinkTuple|string>                                                     $sub
     * @param array{poolAddresses: list<string>, cidr: bool, known: list<string>, template: bool} $tolerance
     *
     * @return array{name: string, status: string, detail: string, differences: list<string>}
     */
    private function clientLinksVsSubLinks(array $own, array $sub, array $tolerance, int $inbounds): array
    {
        $differences = self::multisetDifferences($own, $sub, $tolerance, 'only in links/{email}', 'only in subLinks');

        return [
            'name' => 'U6: links/{email} = subLinks',
            'status' => [] === $differences ? self::PASS : self::DIFF,
            'detail' => sprintf('%d vs %d link(s), client on %d inbound(s); the bot stores subLinks only', count($own), count($sub), $inbounds),
            'differences' => $differences,
        ];
    }

    /**
     * @param list<string> $links
     *
     * @return list<ShareLinkTuple|string> a link that cannot be parsed stays a string (reported, never printed whole)
     */
    private static function tuples(array $links): array
    {
        return array_map(static fn (string $l): ShareLinkTuple|string => ShareLinkTuple::parse($l) ?? 'unparsable '.strtolower((string) parse_url($l, PHP_URL_SCHEME)).' link', $links);
    }

    /**
     * Where the panel picks per request: addresses of SNI-pool hosts, whether a CIDR host exists (with the fixed
     * addresses that are not random), and whether a remark is a template.
     *
     * @param array<int, list<array<string, mixed>>> $groups
     *
     * @return array{poolAddresses: list<string>, cidr: bool, known: list<string>, template: bool}
     */
    private static function tolerance(array $groups): array
    {
        $pool = [];
        $known = [];
        $cidr = false;
        $template = false;
        foreach ($groups as $inboundGroups) {
            foreach ($inboundGroups as $group) {
                $isPool = count(array_filter((array) ($group['snis'] ?? []), static fn (mixed $s): bool => '' !== (string) $s)) > 1;
                $template = $template || str_contains((string) ($group['remark'] ?? ''), '{{');
                foreach ((array) ($group['hosts'] ?? []) as $entry) {
                    [$address] = self::addressAndPort((string) $entry, 0, 0);
                    if (str_contains($address, '/')) {
                        $cidr = true;
                        continue;
                    }
                    $known[] = $address;
                    if ($isPool) {
                        $pool[] = $address;
                    }
                }
            }
        }

        return ['poolAddresses' => $pool, 'cidr' => $cidr, 'known' => $known, 'template' => $template];
    }

    /**
     * @param list<ShareLinkTuple|string>                                                     $left
     * @param list<ShareLinkTuple|string>                                                     $right
     * @param array{poolAddresses: list<string>, cidr: bool, known: list<string>, template: bool} $tolerance
     *
     * @return list<string>
     */
    private static function multisetDifferences(array $left, array $right, array $tolerance, string $onlyLeft, string $onlyRight): array
    {
        $key = static fn (ShareLinkTuple|string $t): string => $t instanceof ShareLinkTuple
            ? $t->key(!in_array($t->address, $tolerance['poolAddresses'], true), !($tolerance['cidr'] && !in_array($t->address, $tolerance['known'], true)), !$tolerance['template'])
            : 'unparsable:'.$t;
        $count = [];
        $sample = [];
        foreach ($left as $t) {
            $k = $key($t);
            $count[$k] = ($count[$k] ?? 0) + 1;
            $sample[$k] = $t;
        }
        foreach ($right as $t) {
            $k = $key($t);
            $count[$k] = ($count[$k] ?? 0) - 1;
            $sample[$k] = $t;
        }
        $differences = [];
        foreach ($count as $k => $n) {
            $what = $sample[$k] instanceof ShareLinkTuple ? $sample[$k]->describe() : $sample[$k];
            for ($i = 0; $i < abs($n); ++$i) {
                $differences[] = sprintf('%s: %s', $n > 0 ? $onlyLeft : $onlyRight, $what);
            }
        }

        return $differences;
    }

    /**
     * A host entry "addr", "addr:port" or "[v6]:port" (3x-ui parseHostAndPort), with the group's or the inbound's port.
     *
     * @return array{string, int}
     */
    private static function addressAndPort(string $entry, int $groupPort, int $inboundPort): array
    {
        $entry = strtolower(trim($entry));
        $port = 0;
        if (1 === preg_match('/^\[(.+)\]:(\d+)$/', $entry, $m) || (1 === preg_match('/^([^:\/]+):(\d+)$/', $entry, $m))) {
            [$entry, $port] = [$m[1], (int) $m[2]];
        }

        return [trim($entry, '[]'), $port ?: ($groupPort ?: $inboundPort)];
    }

    /**
     * @return array{exitCode: int, result: string, reason: string, checks: list<array{name: string, status: string, detail: string, differences: list<string>}>}
     */
    private static function cannot(string $reason): array
    {
        return ['exitCode' => 2, 'result' => 'CANNOT CHECK', 'reason' => $reason, 'checks' => []];
    }
}
