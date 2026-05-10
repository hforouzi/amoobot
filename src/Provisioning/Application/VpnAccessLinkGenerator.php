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

        $config = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $externalProxy = $this->extractExternalProxy($config);
        $externalProxyDetected = [] !== $externalProxy;
        $subscriptionUrl = $this->buildSubscriptionUrl($service, $externalProxy) ?? $service->getSubscriptionUrl();

        $hostCandidates = [
            $this->readPathText($externalProxy, 'host'),
            $this->readPathText($externalProxy, 'server'),
            $this->readPathText($externalProxy, 'address'),
            $this->readPathText($externalProxy, 'domain'),
            $this->readPathText($externalProxy, 'externalProxyHost'),
            $this->readPathText($externalProxy, 'external_proxy_host'),
            $inbound->getHost(),
            $this->readPathText($panel->getConfig() ?? [], 'public_host'),
            $panel->getPublicHost(),
        ];
        if (!$externalProxyDetected) {
            $hostCandidates[] = $this->hostFromBaseUrl($panel->getBaseUrl());
        }

        $portCandidates = [
            $this->readPathInt($externalProxy, 'port'),
            $this->readPathInt($externalProxy, 'externalProxyPort'),
            $this->readPathInt($externalProxy, 'external_proxy_port'),
            $inbound->getPort(),
            $this->readPathInt($panel->getConfig() ?? [], 'public_port'),
        ];
        if (!$externalProxyDetected) {
            $portCandidates[] = $this->portFromBaseUrl($panel->getBaseUrl());
        }

        $host = $this->firstText($hostCandidates);
        $port = $this->firstInt($portCandidates);
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
            '[VpnAccessLinkGenerator] external_proxy_detected=%s external_proxy_host="%s" external_proxy_port="%s" generated_subscription_url=%s generated_single_config=%s missing="%s"',
            $externalProxyDetected ? 'yes' : 'no',
            (string) ($this->firstText([
                $this->readPathText($externalProxy, 'host'),
                $this->readPathText($externalProxy, 'server'),
                $this->readPathText($externalProxy, 'address'),
                $this->readPathText($externalProxy, 'domain'),
            ]) ?? ''),
            (string) ($this->firstInt([
                $this->readPathInt($externalProxy, 'port'),
                $this->readPathInt($externalProxy, 'externalProxyPort'),
                $this->readPathInt($externalProxy, 'external_proxy_port'),
            ]) ?? ''),
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

    /**
     * @param array<string, mixed> $externalProxy
     */
    private function buildSubscriptionUrl(VpnService $service, array $externalProxy): ?string
    {
        $panel = $service->getPanel();
        $inbound = $service->getInbound();
        if (null === $panel || null === $inbound) {
            return null;
        }

        $subId = trim((string) ($service->getSubId() ?? ''));
        if ('' === $subId) {
            return null;
        }

        $inboundConfig = is_array($inbound->getConfig()) ? $inbound->getConfig() : [];
        $panelConfig = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $base = $this->firstText([
            $this->readPathText($externalProxy, 'subscription_base_url'),
            $this->readPathText($externalProxy, 'subscriptionBaseUrl'),
            $this->readPathText($externalProxy, 'subscription.url'),
            $this->readPathText($externalProxy, 'subscription.endpoint'),
            $this->readPathText($externalProxy, 'public_base_url'),
            $this->readPathText($externalProxy, 'publicBaseUrl'),
            $this->readPathText($externalProxy, 'publicEndpoint'),
            $this->readPathText($externalProxy, 'endpoint'),
            $this->readPathText($externalProxy, 'url'),
            $this->readPathText($inboundConfig, 'subscription_base_url'),
            $this->readPathText($panelConfig, 'subscription_base_url'),
            trim((string) ($panel->getSubscriptionBaseUrl() ?? '')),
        ]) ?? '';

        if ('' === $base) {
            $externalHost = $this->firstText([
                $this->readPathText($externalProxy, 'host'),
                $this->readPathText($externalProxy, 'server'),
                $this->readPathText($externalProxy, 'address'),
                $this->readPathText($externalProxy, 'domain'),
            ]);
            $externalPort = $this->firstInt([
                $this->readPathInt($externalProxy, 'port'),
                $this->readPathInt($externalProxy, 'externalProxyPort'),
                $this->readPathInt($externalProxy, 'external_proxy_port'),
            ]);
            if (null !== $externalHost) {
                $scheme = 'https';
                if (null !== $externalPort) {
                    $base = sprintf('%s://%s:%d', $scheme, $externalHost, $externalPort);
                } else {
                    $base = sprintf('%s://%s', $scheme, $externalHost);
                }
            }
        }

        if ('' === $base) {
            return null;
        }
        if (false === filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }

        $prefix = $this->firstText([
            $this->readPathText($externalProxy, 'subscription_path_prefix'),
            $this->readPathText($externalProxy, 'subscriptionPathPrefix'),
            $this->readPathText($inboundConfig, 'subscription_path_prefix'),
            $this->readPathText($panelConfig, 'subscription_path_prefix'),
        ]) ?? '';
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

    /**
     * @param array<string, mixed> $inboundConfig
     *
     * @return array<string, mixed>
     */
    private function extractExternalProxy(array $inboundConfig): array
    {
        $merged = [];
        $candidates = [
            $inboundConfig['externalProxy'] ?? null,
            $inboundConfig['external_proxy'] ?? null,
            $inboundConfig['external proxy'] ?? null,
            $inboundConfig['externalProxySettings'] ?? null,
            $inboundConfig['raw']['externalProxy'] ?? null,
            $inboundConfig['raw']['external_proxy'] ?? null,
            $inboundConfig['raw']['external proxy'] ?? null,
            $inboundConfig['raw']['externalProxySettings'] ?? null,
            $inboundConfig['settings']['externalProxy'] ?? null,
            $inboundConfig['settings']['external_proxy'] ?? null,
            $inboundConfig['settings']['externalProxySettings'] ?? null,
            $inboundConfig['streamSettings']['externalProxy'] ?? null,
            $inboundConfig['streamSettings']['external_proxy'] ?? null,
            $inboundConfig['streamSettings']['externalProxySettings'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $normalized = $this->toArray($candidate);
            if ([] !== $normalized) {
                $merged = $this->mergeArrayRecursiveDistinct($merged, $normalized);
            }
        }

        $this->collectExternalProxyLikeObjects($inboundConfig, $merged);

        $host = $this->findScalarByKeyPatterns(
            [$inboundConfig],
            ['externalProxyHost', 'external_proxy_host', 'external proxy host', 'externalProxyDomain', 'external_proxy_domain']
        );
        if (null !== $host && !isset($merged['host'])) {
            $merged['host'] = trim((string) $host);
        }
        $port = $this->findScalarByKeyPatterns(
            [$inboundConfig],
            ['externalProxyPort', 'external_proxy_port', 'external proxy port']
        );
        if (null !== $port && !isset($merged['port']) && is_numeric($port)) {
            $merged['port'] = (int) $port;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $accumulator
     */
    private function collectExternalProxyLikeObjects(array $source, array &$accumulator): void
    {
        foreach ($source as $key => $value) {
            if (is_string($key) && preg_match('/external[\s_]*proxy(?:settings)?/i', $key)) {
                $normalized = $this->toArray($value);
                if ([] !== $normalized) {
                    $accumulator = $this->mergeArrayRecursiveDistinct($accumulator, $normalized);
                }
            }
            if (is_array($value)) {
                $this->collectExternalProxyLikeObjects($value, $accumulator);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string>               $patterns
     */
    private function findScalarByKeyPatterns(array $sources, array $patterns): string|int|float|bool|null
    {
        foreach ($sources as $source) {
            $value = $this->findScalarInArrayByKeyPatterns($source, $patterns);
            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string>   $patterns
     */
    private function findScalarInArrayByKeyPatterns(array $source, array $patterns): string|int|float|bool|null
    {
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                foreach ($patterns as $pattern) {
                    if (0 === strcasecmp($key, $pattern)) {
                        if (is_scalar($value)) {
                            return $value;
                        }
                        if (is_array($value)) {
                            foreach ($value as $nestedValue) {
                                if (is_scalar($nestedValue)) {
                                    return $nestedValue;
                                }
                            }
                        }
                    }
                }
            }
            if (is_array($value)) {
                $nested = $this->findScalarInArrayByKeyPatterns($value, $patterns);
                if (null !== $nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private function mergeArrayRecursiveDistinct(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_string($key) && array_key_exists($key, $left) && is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->mergeArrayRecursiveDistinct($left[$key], $value);
                continue;
            }
            $left[$key] = $value;
        }

        return $left;
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
