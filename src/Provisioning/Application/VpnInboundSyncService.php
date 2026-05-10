<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;
use Doctrine\ORM\EntityManagerInterface;

final class VpnInboundSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiApiClient $apiClient,
    ) {
    }

    public function syncPanelInbounds(VpnPanel $panel, bool $force = false): VpnInboundSyncResult
    {
        $this->ensureSupportedPanel($panel);

        $result = $this->apiClient->listInbounds($panel);
        if (($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException($this->buildSafeError('Failed to fetch inbound list', $result));
        }

        $payload = $result['data'];
        $rows = $payload['obj'] ?? $payload;
        if (!is_array($rows)) {
            return new VpnInboundSyncResult(0);
        }

        $remoteIds = [];
        $synced = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $remoteInboundId = trim((string) ($row['id'] ?? ''));
            if ('' === $remoteInboundId) {
                continue;
            }

            $remoteIds[] = $remoteInboundId;
            $this->upsertInboundFromRemote($panel, $row, null, $force);
            ++$synced;
        }

        $warnings = [];
        $missingLocalCount = 0;
        $localRows = $this->entityManager->getRepository(VpnInbound::class)->findBy(['panel' => $panel]);
        foreach ($localRows as $localInbound) {
            if (!$localInbound instanceof VpnInbound) {
                continue;
            }

            if (!in_array($localInbound->getRemoteInboundId(), $remoteIds, true)) {
                ++$missingLocalCount;
                $warnings[] = sprintf(
                    'Inbound #%d (%s) is missing on remote panel.',
                    $localInbound->getId() ?? 0,
                    $localInbound->getRemoteInboundId()
                );
                error_log(sprintf(
                    '[VpnInboundSyncService] inbound_missing_on_panel panel_id=%d local_inbound_id=%d remote_inbound_id="%s"',
                    $panel->getId() ?? 0,
                    $localInbound->getId() ?? 0,
                    $localInbound->getRemoteInboundId()
                ));
            }
        }

        $this->entityManager->flush();

        return new VpnInboundSyncResult($synced, $missingLocalCount, $warnings);
    }

    public function syncInbound(VpnInbound $inbound, bool $force = false): VpnInboundSyncResult
    {
        $panel = $inbound->getPanel();
        $this->ensureSupportedPanel($panel);

        $result = $this->apiClient->getInbound($panel, $inbound->getRemoteInboundId());
        if (($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException($this->buildSafeError('Failed to fetch inbound', $result));
        }

        $payload = $result['data'];
        $row = $payload['obj'] ?? $payload;
        if (!is_array($row)) {
            throw new \RuntimeException('Inbound response payload is empty or invalid.');
        }

        $this->upsertInboundFromRemote($panel, $row, $inbound, $force);
        $this->entityManager->flush();

        return new VpnInboundSyncResult(1);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertInboundFromRemote(VpnPanel $panel, array $row, ?VpnInbound $existingInbound, bool $force = false): ?VpnInbound
    {
        $parsed = $this->parseInboundRow($row);
        if ('' === $parsed['remoteInboundId']) {
            return null;
        }

        $inbound = $existingInbound;
        if (!$inbound instanceof VpnInbound) {
            $inbound = $this->entityManager->getRepository(VpnInbound::class)->findOneBy([
                'panel' => $panel,
                'remoteInboundId' => $parsed['remoteInboundId'],
            ]);
        }

        if (!$inbound instanceof VpnInbound) {
            $inbound = (new VpnInbound())
                ->setPanel($panel)
                ->setRemoteInboundId($parsed['remoteInboundId']);
            $this->entityManager->persist($inbound);
        }

        if ($force || '' === trim((string) $inbound->getTitle())) {
            $inbound->setTitle($parsed['title']);
        }

        $inbound
            ->setRemark($parsed['remark'])
            ->setProtocol($parsed['protocol'])
            ->setNetwork($parsed['network'])
            ->setSecurity($parsed['security'])
            ->setHost($parsed['host'] ?? $this->resolveInboundHost($panel, $inbound, $force))
            ->setPort($parsed['port'])
            ->setSni($parsed['sni'])
            ->setPath($parsed['path'])
            ->setHostHeader($parsed['hostHeader'])
            ->setPublicKey($parsed['publicKey'])
            ->setShortId($parsed['shortId'])
            ->setSpiderX($parsed['spiderX'])
            ->setFlow($parsed['flow'])
            ->setServiceName($parsed['serviceName'])
            ->setFingerprint($parsed['fingerprint'])
            ->setAlpn($parsed['alpn'])
            ->setConfig($parsed['config'])
            ->setIsActive($parsed['isActive'])
            ->setLastSyncedAt(new \DateTimeImmutable())
            ->setLastAccessMetadataSyncedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->logInboundSyncSummary($inbound);

        return $inbound;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   remoteInboundId: string,
     *   title: string,
     *   remark: ?string,
     *   protocol: ?string,
     *   network: ?string,
     *   security: ?string,
     *   host: ?string,
     *   port: ?int,
     *   sni: ?string,
     *   path: ?string,
     *   hostHeader: ?string,
     *   publicKey: ?string,
     *   shortId: ?string,
     *   spiderX: ?string,
     *   flow: ?string,
     *   serviceName: ?string,
     *   fingerprint: ?string,
     *   alpn: ?string,
     *   config: array<string, mixed>,
     *   isActive: bool,
     * }
     */
    private function parseInboundRow(array $row): array
    {
        $remoteInboundId = trim((string) ($row['id'] ?? ''));
        $remark = $this->nullableText($row['remark'] ?? null, 'remark');
        $protocol = $this->nullableText($row['protocol'] ?? null, 'protocol');
        $settingsPayload = $this->decodeInboundSection($row['settings'] ?? null, 'settings', $remoteInboundId);
        $streamSettingsPayload = $this->decodeInboundSection($row['streamSettings'] ?? null, 'streamSettings', $remoteInboundId);
        $sniffingPayload = $this->decodeInboundSection($row['sniffing'] ?? null, 'sniffing', $remoteInboundId);
        $settings = is_array($settingsPayload) ? $settingsPayload : [];
        $streamSettings = is_array($streamSettingsPayload) ? $streamSettingsPayload : [];
        $externalProxy = $this->extractExternalProxy($row, $settings, $streamSettings);
        $tlsSettings = $this->jsonToArray($streamSettings['tlsSettings'] ?? null);
        $realitySettings = $this->jsonToArray($streamSettings['realitySettings'] ?? null);
        $wsSettings = $this->jsonToArray($streamSettings['wsSettings'] ?? null);
        $grpcSettings = $this->jsonToArray($streamSettings['grpcSettings'] ?? null);
        $tcpSettings = $this->jsonToArray($streamSettings['tcpSettings'] ?? null);

        $network = $this->externalProxyText($externalProxy, ['network', 'type', 'stream.network'], 'externalProxy.network')
            ?? $this->nullableText($streamSettings['network'] ?? null, 'streamSettings.network');
        $security = $this->externalProxyText($externalProxy, ['security', 'stream.security', 'tls.security'], 'externalProxy.security')
            ?? $this->nullableText($streamSettings['security'] ?? null, 'streamSettings.security');
        $port = $this->externalProxyInt($externalProxy, ['port'], 'externalProxy.port')
            ?? $this->nullableInt($row['port'] ?? null, 'port');
        $title = $remark ?? ('Inbound '.$remoteInboundId);
        $isActive = isset($row['enable']) ? (bool) $row['enable'] : true;
        $clients = is_array($settings['clients'] ?? null) ? $settings['clients'] : [];
        $firstClient = is_array($clients[0] ?? null) ? $clients[0] : [];

        $tlsFingerprint = $this->nullableText($tlsSettings['fingerprint'] ?? null, 'tlsSettings.fingerprint');
        $tlsAlpnRaw = $tlsSettings['alpn'] ?? null;
        $tlsAlpn = null;
        if (is_array($tlsAlpnRaw) && [] !== $tlsAlpnRaw) {
            $tlsAlpn = $this->nullableText(implode(',', array_map('strval', $tlsAlpnRaw)), 'tlsSettings.alpn');
        } else {
            $tlsAlpn = $this->nullableText($tlsAlpnRaw, 'tlsSettings.alpn');
        }

        $realityServerNames = is_array($realitySettings['serverNames'] ?? null) ? $realitySettings['serverNames'] : [];
        $realityShortIds = is_array($realitySettings['shortIds'] ?? null) ? $realitySettings['shortIds'] : [];
        $realityPublicKey = $this->nullableText($realitySettings['publicKey'] ?? ($realitySettings['settings']['publicKey'] ?? null), 'realitySettings.publicKey');
        $realityFingerprint = $this->nullableText($realitySettings['fingerprint'] ?? ($realitySettings['settings']['fingerprint'] ?? null), 'realitySettings.fingerprint');
        $realitySpiderX = $this->nullableText($realitySettings['spiderX'] ?? ($realitySettings['settings']['spiderX'] ?? null), 'realitySettings.spiderX');

        $wsHeaders = $this->jsonToArray($wsSettings['headers'] ?? null);
        $hostHeader = $this->nullableText($wsHeaders['Host'] ?? ($wsHeaders['host'] ?? null), 'wsSettings.headers.Host');
        $path = $this->nullableText($wsSettings['path'] ?? null, 'wsSettings.path');
        $serviceName = $this->nullableText($grpcSettings['serviceName'] ?? null, 'grpcSettings.serviceName');
        if ('tcp' === strtolower((string) ($network ?? '')) && null === $hostHeader) {
            $hostHeader = $this->extractTcpHostHeader($tcpSettings);
        }

        $sni = $this->nullableText($tlsSettings['serverName'] ?? null, 'tlsSettings.serverName');
        $publicKey = null;
        $shortId = null;
        $spiderX = null;
        $fingerprint = $tlsFingerprint;
        if ('reality' === strtolower((string) ($security ?? ''))) {
            $sni = $this->nullableText($realityServerNames[0] ?? null, 'realitySettings.serverNames[0]');
            $publicKey = $realityPublicKey;
            $shortId = $this->nullableText($realityShortIds[0] ?? null, 'realitySettings.shortIds[0]');
            $spiderX = $realitySpiderX;
            $fingerprint = $realityFingerprint;
        }
        $flow = $this->nullableText($firstClient['flow'] ?? null, 'settings.clients[0].flow');
        if (null === $flow) {
            $flow = $this->nullableText($streamSettings['flow'] ?? ($settings['flow'] ?? null), 'flow');
        }
        $host = $this->externalProxyText($externalProxy, ['host', 'server', 'address', 'domain', 'publicHost'], 'externalProxy.host');
        $sni = $this->externalProxyText($externalProxy, ['sni', 'serverName', 'tls.sni', 'reality.sni'], 'externalProxy.sni') ?? $sni;
        $path = $this->externalProxyText($externalProxy, ['path', 'ws.path'], 'externalProxy.path') ?? $path;
        $hostHeader = $this->externalProxyText($externalProxy, ['hostHeader', 'headers.Host', 'ws.host', 'host'], 'externalProxy.hostHeader') ?? $hostHeader;
        $publicKey = $this->externalProxyText($externalProxy, ['publicKey', 'pbk', 'reality.publicKey'], 'externalProxy.publicKey') ?? $publicKey;
        $shortId = $this->externalProxyText($externalProxy, ['shortId', 'sid', 'reality.shortId'], 'externalProxy.shortId') ?? $shortId;
        $spiderX = $this->externalProxyText($externalProxy, ['spiderX', 'spx', 'reality.spiderX'], 'externalProxy.spiderX') ?? $spiderX;
        $fingerprint = $this->externalProxyText($externalProxy, ['fingerprint', 'fp', 'tls.fingerprint', 'reality.fingerprint'], 'externalProxy.fingerprint') ?? $fingerprint;
        $alpnFromExternalProxy = $this->externalProxyText($externalProxy, ['alpn', 'tls.alpn'], 'externalProxy.alpn');
        $flow = $this->externalProxyText($externalProxy, ['flow'], 'externalProxy.flow') ?? $flow;
        $serviceName = $this->externalProxyText($externalProxy, ['serviceName', 'grpc.serviceName'], 'externalProxy.serviceName') ?? $serviceName;
        $tlsAlpn = $alpnFromExternalProxy ?? $tlsAlpn;

        error_log(sprintf(
            '[VpnInboundSyncService] external_proxy_detected remote_inbound_id="%s" detected=%s host="%s" port="%s"',
            $remoteInboundId,
            [] !== $externalProxy ? 'yes' : 'no',
            (string) ($host ?? ''),
            (string) ($port ?? '')
        ));

        return [
            'remoteInboundId' => $remoteInboundId,
            'title' => $title,
            'remark' => $remark,
            'protocol' => $protocol,
            'host' => $host,
            'network' => $network,
            'security' => $security,
            'port' => $port,
            'sni' => $sni,
            'path' => $path,
            'hostHeader' => $hostHeader,
            'publicKey' => $publicKey,
            'shortId' => $shortId,
            'spiderX' => $spiderX,
            'flow' => $flow,
            'serviceName' => $serviceName,
            'fingerprint' => $fingerprint,
            'alpn' => $tlsAlpn,
            'config' => [
                'raw' => $this->sanitizeInboundData($row),
                'settings' => $this->sanitizeInboundData($settingsPayload),
                'streamSettings' => $this->sanitizeInboundData($streamSettingsPayload),
                'sniffing' => $this->sanitizeInboundData($sniffingPayload),
                'externalProxy' => $this->sanitizeInboundData($externalProxy),
            ],
            'isActive' => $isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonToArray(mixed $value): array
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $streamSettings
     *
     * @return array<string, mixed>
     */
    private function extractExternalProxy(array $row, array $settings, array $streamSettings): array
    {
        $merged = [];
        $candidates = [
            $row['externalProxy'] ?? null,
            $row['external_proxy'] ?? null,
            $row['external proxy'] ?? null,
            $row['externalProxySettings'] ?? null,
            $row['external_proxy_settings'] ?? null,
            $settings['externalProxy'] ?? null,
            $settings['external_proxy'] ?? null,
            $settings['external proxy'] ?? null,
            $settings['externalProxySettings'] ?? null,
            $settings['external_proxy_settings'] ?? null,
            $streamSettings['externalProxy'] ?? null,
            $streamSettings['external_proxy'] ?? null,
            $streamSettings['external proxy'] ?? null,
            $streamSettings['externalProxySettings'] ?? null,
            $streamSettings['external_proxy_settings'] ?? null,
            $streamSettings['sockopt'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->jsonToArray($candidate);
            if ([] !== $normalized) {
                $merged = $this->mergeArrayRecursiveDistinct($merged, $normalized);
            }
        }

        $this->collectExternalProxyLikeObjects($row, $merged);

        $host = $this->findScalarByKeyPatterns(
            [$row, $settings, $streamSettings],
            ['externalProxyHost', 'external_proxy_host', 'external proxy host', 'externalProxyDomain', 'external_proxy_domain']
        );
        if (null !== $host && !isset($merged['host'])) {
            $merged['host'] = trim((string) $host);
        }

        $port = $this->findScalarByKeyPatterns(
            [$row, $settings, $streamSettings],
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
                $normalized = $this->jsonToArray($value);
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
                        $scalar = $this->extractFirstScalar($value, 'externalProxyPattern.'.$pattern);
                        if (null !== $scalar) {
                            return $scalar;
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

    /**
     * @param array<string, mixed> $externalProxy
     * @param array<int, string>   $paths
     */
    private function externalProxyText(array $externalProxy, array $paths, string $field): ?string
    {
        foreach ($paths as $path) {
            $value = $this->readPath($externalProxy, $path);
            $text = $this->nullableText($value, $field.'.'.$path);
            if (null !== $text) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $externalProxy
     * @param array<int, string>   $paths
     */
    private function externalProxyInt(array $externalProxy, array $paths, string $field): ?int
    {
        foreach ($paths as $path) {
            $value = $this->readPath($externalProxy, $path);
            $intValue = $this->nullableInt($value, $field.'.'.$path);
            if (null !== $intValue) {
                return $intValue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function nullableText(mixed $value, string $field): ?string
    {
        $scalar = $this->extractFirstScalar($value, $field);
        if (null === $scalar) {
            return null;
        }

        $text = trim((string) $scalar);

        return '' === $text ? null : $text;
    }

    private function nullableInt(mixed $value, string $field): ?int
    {
        $scalar = $this->extractFirstScalar($value, $field);
        if (null === $scalar || is_bool($scalar) || !is_numeric($scalar)) {
            if (null !== $scalar) {
                $this->logTypeMismatch($field, $value, 'numeric-scalar');
            }

            return null;
        }

        $intValue = (int) $scalar;

        if ($intValue < 1 || $intValue > 65535) {
            $this->logTypeMismatch($field, $value, 'port-range(1..65535)');

            return null;
        }

        return $intValue;
    }

    private function extractFirstScalar(mixed $value, string $field): string|int|float|bool|null
    {
        if (null === $value) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $this->logTypeMismatch($field, $value, 'scalar');
            foreach ($value as $item) {
                if (null !== $item) {
                    return $this->extractFirstScalar($item, $field);
                }
            }

            return null;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $this->logTypeMismatch($field, $value, 'scalar');

                return trim((string) $value);
            }

            $this->logTypeMismatch($field, $value, 'scalar');

            return null;
        }

        return null;
    }

    private function logTypeMismatch(string $field, mixed $value, string $expected): void
    {
        $type = get_debug_type($value);
        $preview = '';
        if (is_scalar($value)) {
            $preview = (string) $value;
        } elseif (is_array($value)) {
            $preview = sprintf('array(len=%d)', count($value));
        } elseif (is_object($value)) {
            $preview = 'object';
        }

        error_log(sprintf(
            '[VpnInboundSyncService] metadata_type_mismatch field="%s" expected="%s" got="%s" preview="%s"',
            $field,
            $expected,
            $type,
            mb_substr($preview, 0, 120)
        ));
    }

    private function resolveInboundHost(VpnPanel $panel, VpnInbound $inbound, bool $force): ?string
    {
        $existingHost = trim((string) ($inbound->getHost() ?? ''));
        if (!$force && '' !== $existingHost) {
            return $existingHost;
        }

        $panelConfig = $panel->getConfig();
        $configHost = $this->nullableText(is_array($panelConfig) ? ($panelConfig['public_host'] ?? null) : null, 'panel.config.public_host');
        if (null !== $configHost) {
            return $configHost;
        }

        $baseUrlHost = $this->hostFromBaseUrl($panel->getBaseUrl());
        if (null !== $baseUrlHost) {
            return $baseUrlHost;
        }

        error_log(sprintf(
            '[VpnInboundSyncService] inbound_host_missing panel_id=%d remote_inbound_id="%s"',
            $panel->getId() ?? 0,
            $inbound->getRemoteInboundId()
        ));

        return null;
    }

    private function hostFromBaseUrl(?string $baseUrl): ?string
    {
        if (null === $baseUrl || '' === trim($baseUrl)) {
            return null;
        }

        $host = parse_url(trim($baseUrl), PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }

        $host = trim($host);

        return '' === $host ? null : $host;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function decodeInboundSection(mixed $value, string $field, string $remoteInboundId): array|string|null
    {
        if (null === $value) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            $this->logTypeMismatch($field, $value, 'array-or-json-string');

            return null;
        }

        $text = trim($value);
        if ('' === $text) {
            return null;
        }

        $decoded = json_decode($text, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            error_log(sprintf(
                '[VpnInboundSyncService] inbound_section_invalid_json remote_inbound_id="%s" field="%s"',
                $remoteInboundId,
                $field
            ));

            return $text;
        }

        return $decoded;
    }

    private function extractTcpHostHeader(array $tcpSettings): ?string
    {
        $header = $this->jsonToArray($tcpSettings['header'] ?? null);
        $request = $this->jsonToArray($header['request'] ?? null);
        $headers = $this->jsonToArray($request['headers'] ?? null);
        $host = $headers['Host'] ?? ($headers['host'] ?? null);

        return $this->nullableText($host, 'tcpSettings.header.request.headers.Host');
    }

    private function logInboundSyncSummary(VpnInbound $inbound): void
    {
        error_log(sprintf(
            '[VpnInboundSyncService] inbound_synced remote_inbound_id="%s" protocol="%s" port="%s" network="%s" security="%s" host="%s" sni="%s" path="%s" public_key=%s short_id=%s',
            $inbound->getRemoteInboundId(),
            (string) ($inbound->getProtocol() ?? ''),
            (string) ($inbound->getPort() ?? ''),
            (string) ($inbound->getNetwork() ?? ''),
            (string) ($inbound->getSecurity() ?? ''),
            (string) ($inbound->getHost() ?? ''),
            (string) ($inbound->getSni() ?? ''),
            (string) ($inbound->getPath() ?? ''),
            null !== $inbound->getPublicKey() && '' !== trim($inbound->getPublicKey()) ? 'yes' : 'no',
            null !== $inbound->getShortId() && '' !== trim($inbound->getShortId()) ? 'yes' : 'no'
        ));
    }

    private function sanitizeInboundData(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(?:password|passwd|token|cookie|session|authorization)/i', $key)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }

                $sanitized[$key] = $this->sanitizeInboundData($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return '[object]';
        }

        return $value;
    }

    private function ensureSupportedPanel(VpnPanel $panel): void
    {
        if ('sanaei_3xui' !== $panel->getType()) {
            throw new \RuntimeException('Panel type must be sanaei_3xui.');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function buildSafeError(string $prefix, array $result): string
    {
        $status = $result['status'] ?? null;
        $error = trim((string) ($result['error'] ?? 'unknown'));

        if (null === $status) {
            return sprintf('%s (%s).', $prefix, $error);
        }

        return sprintf('%s (status: %s, error: %s).', $prefix, (string) $status, $error);
    }
}
