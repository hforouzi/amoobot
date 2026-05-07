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
            ->setConfig($parsed['config'])
            ->setIsActive($parsed['isActive'])
            ->setLastSyncedAt(new \DateTimeImmutable())
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
     *   isActive: bool,
     *   config: array<string, mixed>
     * }
     */
    private function parseInboundRow(array $row): array
    {
        $remoteInboundId = trim((string) ($row['id'] ?? ''));
        $remark = $this->nullableText($row['remark'] ?? null);
        $protocol = $this->nullableText($row['protocol'] ?? null);
        $streamSettings = $this->jsonToArray($row['streamSettings'] ?? null);
        $settings = $this->jsonToArray($row['settings'] ?? null);
        $sniffing = $this->jsonToArray($row['sniffing'] ?? null);
        $tlsSettings = $this->jsonToArray($streamSettings['tlsSettings'] ?? null);
        $realitySettings = $this->jsonToArray($streamSettings['realitySettings'] ?? null);
        $wsSettings = $this->jsonToArray($streamSettings['wsSettings'] ?? null);
        $grpcSettings = $this->jsonToArray($streamSettings['grpcSettings'] ?? null);

        $network = $this->nullableText($streamSettings['network'] ?? null);
        $security = $this->nullableText($streamSettings['security'] ?? null);
        $port = isset($row['port']) ? (int) $row['port'] : null;

        $title = $remark ?? ('Inbound '.$remoteInboundId);
        $isActive = isset($row['enable']) ? (bool) $row['enable'] : true;

        return [
            'remoteInboundId' => $remoteInboundId,
            'title' => $title,
            'remark' => $remark,
            'protocol' => $protocol,
            'network' => $network,
            'security' => $security,
            'isActive' => $isActive,
            'config' => [
                'id' => $row['id'] ?? null,
                'remark' => $row['remark'] ?? null,
                'protocol' => $row['protocol'] ?? null,
                'enable' => $row['enable'] ?? null,
                'port' => $port,
                'total' => $row['total'] ?? null,
                'up' => $row['up'] ?? null,
                'down' => $row['down'] ?? null,
                'sniffing' => $sniffing,
                'settings' => $settings,
                'streamSettings' => $streamSettings,
                'tlsSettings' => $tlsSettings,
                'realitySettings' => $realitySettings,
                'wsSettings' => $wsSettings,
                'grpcSettings' => $grpcSettings,
                'raw' => $row,
            ],
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

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return '' === $text ? null : $text;
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
