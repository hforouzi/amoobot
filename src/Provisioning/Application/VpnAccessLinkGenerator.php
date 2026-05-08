<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnService;

final class VpnAccessLinkGenerator
{
    /**
     * @return array{subscriptionUrl: ?string, configLinks: array<int, string>, missing: array<int, string>}
     */
    public function generate(VpnService $service): array
    {
        $inbound = $service->getInbound();
        $panel = $service->getPanel();
        if (null === $inbound || null === $panel) {
            return [
                'subscriptionUrl' => null,
                'configLinks' => [],
                'missing' => ['inbound_or_panel'],
            ];
        }

        $host = trim((string) ($inbound->getHost() ?? $panel->getPublicHost() ?? ''));
        if ('' === $host) {
            $host = trim((string) (parse_url((string) ($panel->getBaseUrl() ?? ''), PHP_URL_HOST) ?? ''));
        }
        $port = $inbound->getPort();
        $protocol = strtolower(trim((string) ($inbound->getProtocol() ?? '')));
        $network = strtolower(trim((string) ($inbound->getNetwork() ?? 'tcp')));
        $security = strtolower(trim((string) ($inbound->getSecurity() ?? 'none')));
        $clientUuid = trim((string) ($service->getClientUuid() ?? ''));
        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
        $subId = trim((string) ($service->getSubId() ?? ''));

        $subscriptionBaseUrl = trim((string) ($panel->getSubscriptionBaseUrl() ?? ''));
        $subscriptionUrl = ('' !== $subscriptionBaseUrl && '' !== $subId)
            ? rtrim($subscriptionBaseUrl, '/').'/sub/'.rawurlencode($subId)
            : null;

        $missing = [];
        if ('' === $host) {
            $missing[] = 'host';
        }
        if (null === $port || $port <= 0) {
            $missing[] = 'port';
        }
        if ('' === $protocol) {
            $missing[] = 'protocol';
        }
        if ('' === $clientUuid) {
            $missing[] = 'clientUuid';
        }
        if ([] !== $missing) {
            return [
                'subscriptionUrl' => $subscriptionUrl,
                'configLinks' => [],
                'missing' => $missing,
            ];
        }

        $name = rawurlencode(trim((string) ($inbound->getTitle() ?: $email ?: 'service')));
        $configLinks = [];

        if ('vless' === $protocol) {
            $query = [
                'type' => $network,
                'security' => $security,
                'encryption' => 'none',
            ];

            if ('reality' === $security) {
                $publicKey = trim((string) ($inbound->getPublicKey() ?? ''));
                if ('' === $publicKey) {
                    return [
                        'subscriptionUrl' => $subscriptionUrl,
                        'configLinks' => [],
                        'missing' => array_values(array_unique([...$missing, 'publicKey'])),
                    ];
                }
                $query['pbk'] = $publicKey;
                if (null !== $inbound->getSni()) {
                    $query['sni'] = (string) $inbound->getSni();
                }
                if (null !== $inbound->getShortId()) {
                    $query['sid'] = (string) $inbound->getShortId();
                }
                if (null !== $inbound->getSpiderX()) {
                    $query['spx'] = (string) $inbound->getSpiderX();
                }
                if (null !== $inbound->getFingerprint()) {
                    $query['fp'] = (string) $inbound->getFingerprint();
                }
            }

            if ('tls' === $security || 'xtls' === $security) {
                if (null !== $inbound->getSni()) {
                    $query['sni'] = (string) $inbound->getSni();
                }
                if (null !== $inbound->getAlpn()) {
                    $query['alpn'] = (string) $inbound->getAlpn();
                }
            }

            if ('ws' === $network) {
                $query['path'] = (string) ($inbound->getPath() ?? '/');
                if (null !== $inbound->getHostHeader()) {
                    $query['host'] = (string) $inbound->getHostHeader();
                }
            }

            if ('grpc' === $network && null !== $inbound->getServiceName()) {
                $query['serviceName'] = (string) $inbound->getServiceName();
            }

            if (null !== $inbound->getFlow()) {
                $query['flow'] = (string) $inbound->getFlow();
            }

            $queryString = http_build_query(array_filter($query, static fn (mixed $v): bool => '' !== trim((string) $v)), '', '&', PHP_QUERY_RFC3986);
            $configLinks[] = sprintf('vless://%s@%s:%d?%s#%s', rawurlencode($clientUuid), $host, $port, $queryString, $name);
        } elseif ('vmess' === $protocol) {
            $vmess = [
                'v' => '2',
                'ps' => rawurldecode($name),
                'add' => $host,
                'port' => (string) $port,
                'id' => $clientUuid,
                'aid' => '0',
                'net' => $network,
                'type' => 'none',
                'host' => (string) ($inbound->getHostHeader() ?? ''),
                'path' => (string) ($inbound->getPath() ?? ''),
                'tls' => ('tls' === $security || 'reality' === $security) ? 'tls' : '',
                'sni' => (string) ($inbound->getSni() ?? ''),
            ];
            $configLinks[] = 'vmess://'.base64_encode(json_encode($vmess, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } elseif ('trojan' === $protocol) {
            $query = [
                'type' => $network,
                'security' => $security,
            ];
            if (null !== $inbound->getSni()) {
                $query['sni'] = (string) $inbound->getSni();
            }
            $queryString = http_build_query(array_filter($query, static fn (mixed $v): bool => '' !== trim((string) $v)), '', '&', PHP_QUERY_RFC3986);
            $configLinks[] = sprintf('trojan://%s@%s:%d?%s#%s', rawurlencode($clientUuid), $host, $port, $queryString, $name);
        }

        if ([] === $configLinks) {
            $missing[] = 'unsupported_or_incomplete_protocol';
        }

        return [
            'subscriptionUrl' => $subscriptionUrl,
            'configLinks' => $configLinks,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}

