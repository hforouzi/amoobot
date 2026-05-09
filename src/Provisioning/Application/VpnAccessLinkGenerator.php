<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnPanel;
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

        $subscriptionUrl = $this->buildSubscriptionUrl($panel, trim((string) ($service->getSubId() ?? ''))) ?? $service->getSubscriptionUrl();
        $config = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $externalProxy = $this->toArray($config['externalProxy'] ?? []);
        $externalProxyDetected = [] !== $externalProxy;

        $host = $this->firstText([
            $this->readPathText($externalProxy, 'host'),
            $this->readPathText($externalProxy, 'server'),
            $this->readPathText($externalProxy, 'address'),
            $this->readPathText($externalProxy, 'domain'),
            $inbound->getHost(),
            $this->readPathText($panel->getConfig() ?? [], 'public_host'),
            $panel->getPublicHost(),
            $this->hostFromBaseUrl($panel->getBaseUrl()),
        ]);
        $port = $this->firstInt([
            $this->readPathInt($externalProxy, 'port'),
            $inbound->getPort(),
            $this->readPathInt($panel->getConfig() ?? [], 'public_port'),
            $this->portFromBaseUrl($panel->getBaseUrl()),
        ]);
        $network = strtolower($this->firstText([
            $this->readPathText($externalProxy, 'network'),
            $this->readPathText($externalProxy, 'type'),
            $inbound->getNetwork(),
            'tcp',
        ]) ?? 'tcp');
        $security = strtolower($this->firstText([
            $this->readPathText($externalProxy, 'security'),
            $inbound->getSecurity(),
            'none',
        ]) ?? 'none');

        $clientUuid = trim((string) ($service->getClientUuid() ?? ''));
        $email = trim((string) ($service->getClientEmail() ?? $service->getUsername() ?? ''));
        $protocol = strtolower(trim((string) ($inbound->getProtocol() ?? '')));

        $missing = [];
        if ('' === $host) {
            $missing[] = 'host';
        }
        if (null === $port || $port <= 0) {
            $missing[] = 'port';
        }
        if ('' === $clientUuid) {
            $missing[] = 'clientUuid';
        }
        if ('' === $protocol) {
            $missing[] = 'protocol';
        }

        $configLinks = [];
        if ('vless' === $protocol) {
            $vless = $this->buildVlessLink($service, $host, $port, $network, $security, $externalProxy, $missing, $clientUuid, $email);
            if (null !== $vless) {
                $configLinks[] = $vless;
            }
        } else {
            $missing[] = 'unsupported_protocol_for_single_link';
        }

        error_log(sprintf(
            '[VpnAccessLinkGenerator] external_proxy_detected=%s generated_subscription_url=%s generated_single_config=%s missing="%s"',
            $externalProxyDetected ? 'yes' : 'no',
            '' !== trim((string) ($subscriptionUrl ?? '')) ? 'yes' : 'no',
            [] !== $configLinks ? 'yes' : 'no',
            implode(',', array_values(array_unique($missing)))
        ));

        return [
            'subscriptionUrl' => $subscriptionUrl,
            'configLinks' => $configLinks,
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @param array<string, mixed> $externalProxy
     * @param array<int, string>   $missing
     */
    private function buildVlessLink(VpnService $service, string $host, ?int $port, string $network, string $security, array $externalProxy, array &$missing, string $clientUuid, string $email): ?string
    {
        if ('' === $host || null === $port || '' === $clientUuid) {
            return null;
        }

        $inbound = $service->getInbound();
        if (null === $inbound) {
            $missing[] = 'inbound';

            return null;
        }

        $sni = $this->firstText([
            $this->readPathText($externalProxy, 'sni'),
            $this->readPathText($externalProxy, 'serverName'),
            $inbound->getSni(),
        ]);
        $path = $this->firstText([
            $this->readPathText($externalProxy, 'path'),
            $inbound->getPath(),
        ]);
        $hostHeader = $this->firstText([
            $this->readPathText($externalProxy, 'hostHeader'),
            $this->readPathText($externalProxy, 'headers.Host'),
            $inbound->getHostHeader(),
        ]);
        $publicKey = $this->firstText([
            $this->readPathText($externalProxy, 'publicKey'),
            $this->readPathText($externalProxy, 'pbk'),
            $inbound->getPublicKey(),
        ]);
        $shortId = $this->firstText([
            $this->readPathText($externalProxy, 'shortId'),
            $this->readPathText($externalProxy, 'sid'),
            $inbound->getShortId(),
        ]);
        $spiderX = $this->firstText([
            $this->readPathText($externalProxy, 'spiderX'),
            $this->readPathText($externalProxy, 'spx'),
            $inbound->getSpiderX(),
        ]);
        $fingerprint = $this->firstText([
            $this->readPathText($externalProxy, 'fingerprint'),
            $this->readPathText($externalProxy, 'fp'),
            $inbound->getFingerprint(),
        ]);
        $alpn = $this->firstText([
            $this->readPathText($externalProxy, 'alpn'),
            $inbound->getAlpn(),
        ]);
        $flow = $this->firstText([
            $this->readPathText($externalProxy, 'flow'),
            $inbound->getFlow(),
        ]);
        $serviceName = $this->firstText([
            $this->readPathText($externalProxy, 'serviceName'),
            $inbound->getServiceName(),
        ]);

        $query = [
            'type' => $network,
            'security' => $security,
            'encryption' => 'none',
        ];

        if ('reality' === $security) {
            if (null === $publicKey || '' === $publicKey) {
                $missing[] = 'publicKey';

                return null;
            }
            $query['pbk'] = $publicKey;
            if (null !== $fingerprint) {
                $query['fp'] = $fingerprint;
            }
            if (null !== $sni) {
                $query['sni'] = $sni;
            }
            if (null !== $shortId) {
                $query['sid'] = $shortId;
            }
            if (null !== $spiderX) {
                $query['spx'] = $spiderX;
            }
            if (null !== $flow) {
                $query['flow'] = $flow;
            }
        } elseif ('tls' === $security && null !== $sni) {
            $query['sni'] = $sni;
        }

        if ('ws' === $network) {
            $query['path'] = null !== $path ? $path : '/';
            if (null !== $hostHeader) {
                $query['host'] = $hostHeader;
            }
        }

        if ('grpc' === $network && null !== $serviceName) {
            $query['serviceName'] = $serviceName;
        }

        if (null !== $alpn) {
            $query['alpn'] = $alpn;
        }

        $queryString = http_build_query(array_filter($query, static fn (mixed $value): bool => '' !== trim((string) $value)), '', '&', PHP_QUERY_RFC3986);
        $name = rawurlencode(trim((string) ($inbound->getRemark() ?? $inbound->getTitle() ?? $email ?: 'service')));

        return sprintf('vless://%s@%s:%d?%s#%s', rawurlencode($clientUuid), $host, $port, $queryString, $name);
    }

    private function buildSubscriptionUrl(VpnPanel $panel, string $subId): ?string
    {
        if ('' === $subId) {
            return null;
        }

        $panelConfig = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $base = trim((string) ($panelConfig['subscription_base_url'] ?? ''));
        if ('' === $base) {
            $base = trim((string) ($panel->getSubscriptionBaseUrl() ?? ''));
        }
        if ('' === $base) {
            return null;
        }
        if (false === filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }

        $prefix = trim((string) ($panelConfig['subscription_path_prefix'] ?? ''));
        if ('' === $prefix) {
            $prefix = '/sub';
        }
        $prefix = '/'.trim($prefix, '/');

        $url = rtrim($base, '/').$prefix.'/'.rawurlencode($subId);

        return preg_replace('#(?<!:)/{2,}#', '/', $url) ?? $url;
    }

    private function firstText(array $values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ('' !== $text) {
                return $text;
            }
        }

        return null;
    }

    private function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $int = (int) $value;
                if ($int > 0 && $int <= 65535) {
                    return $int;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readPathText(array $data, string $path): ?string
    {
        $value = $this->readPath($data, $path);
        if (null === $value) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readPathInt(array $data, string $path): ?int
    {
        $value = $this->readPath($data, $path);
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 && $int <= 65535 ? $int : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readPath(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed>|mixed $value
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) ? $decoded : [];
    }

    private function hostFromBaseUrl(?string $baseUrl): ?string
    {
        $host = parse_url(trim((string) $baseUrl), PHP_URL_HOST);

        return is_string($host) && '' !== trim($host) ? trim($host) : null;
    }

    private function portFromBaseUrl(?string $baseUrl): ?int
    {
        $port = parse_url(trim((string) $baseUrl), PHP_URL_PORT);

        return is_int($port) && $port > 0 && $port <= 65535 ? $port : null;
    }
}
