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

    public function syncPanelInbounds(VpnPanel $panel): VpnInboundSyncResult
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
            $this->upsertInboundFromRemote($panel, $row, null);
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

    public function syncInbound(VpnInbound $inbound): VpnInboundSyncResult
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

        $this->upsertInboundFromRemote($panel, $row, $inbound);
        $this->entityManager->flush();

        return new VpnInboundSyncResult(1);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertInboundFromRemote(VpnPanel $panel, array $row, ?VpnInbound $existingInbound): ?VpnInbound
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

        $inbound
            ->setTitle($parsed['title'])
            ->setRemark($parsed['remark'])
            ->setProtocol($parsed['protocol'])
            ->setNetwork($parsed['network'])
            ->setSecurity($parsed['security'])
            ->setHost($parsed['host'] ?? $panel->getPublicHost())
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
            ->setConfig([])
            ->setIsActive($parsed['isActive'])
            ->setLastSyncedAt(new \DateTimeImmutable())
            ->setLastAccessMetadataSyncedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

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
     *   isActive: bool,
     * }
     */
    private function parseInboundRow(array $row): array
    {
        $remoteInboundId = trim((string) ($row['id'] ?? ''));
        $remark = $this->nullableText($row['remark'] ?? null, 'remark');
        $protocol = $this->nullableText($row['protocol'] ?? null, 'protocol');
        $streamSettings = $this->jsonToArray($row['streamSettings'] ?? null);
        $settings = $this->jsonToArray($row['settings'] ?? null);
        $tlsSettings = $this->jsonToArray($streamSettings['tlsSettings'] ?? null);
        $realitySettings = $this->jsonToArray($streamSettings['realitySettings'] ?? null);
        $wsSettings = $this->jsonToArray($streamSettings['wsSettings'] ?? null);
        $grpcSettings = $this->jsonToArray($streamSettings['grpcSettings'] ?? null);

        $network = $this->nullableText($streamSettings['network'] ?? null, 'streamSettings.network');
        $security = $this->nullableText($streamSettings['security'] ?? null, 'streamSettings.security');
        $port = $this->nullableInt($row['port'] ?? null, 'port');
        $listen = $this->nullableText($row['listen'] ?? null, 'listen');
        if (in_array((string) $listen, ['0.0.0.0', '::', '*'], true)) {
            $listen = null;
        }

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

        return [
            'remoteInboundId' => $remoteInboundId,
            'title' => $title,
            'remark' => $remark,
            'protocol' => $protocol,
            'network' => $network,
            'security' => $security,
            'host' => $listen,
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

        return $intValue > 0 ? $intValue : null;
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
