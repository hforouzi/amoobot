<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use Symfony\Component\Uid\Uuid;

final class Sanaei3xuiDriver implements VpnPanelDriverInterface
{
    private const CONFIG_TEXT_WITH_SUBSCRIPTION = 'سرویس با موفقیت در پنل ساخته شد.';
    private const CONFIG_TEXT_SUBSCRIPTION_UNAVAILABLE = 'سرویس در پنل ساخته شد. برای دریافت لینک اشتراک، تنظیمات subscription پنل را بررسی کنید.';

    public function __construct(
        private readonly Sanaei3xuiApiClient $apiClient,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
    ) {
    }

    public function supports(?VpnPanel $panel): bool
    {
        return $panel instanceof VpnPanel && 'sanaei_3xui' === $panel->getType();
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        $panel = $this->requireSupportedPanel($panel);
        $config = $this->panelConfig($panel);
        $inbound = $request->inbound;
        if (null === $inbound) {
            throw new \RuntimeException('Sanaei provisioning requires a selected inbound.');
        }
        if ($inbound->getPanel()->getId() !== $panel->getId()) {
            throw new \RuntimeException('Selected inbound does not belong to selected panel.');
        }

        $inboundId = trim((string) ($request->remoteInboundId ?? $inbound->getRemoteInboundId()));
        if ('' === $inboundId) {
            throw new \RuntimeException('Sanaei provisioning requires a valid remote inbound id.');
        }

        $clientUuid = Uuid::v4()->toRfc4122();
        $email = $request->username;
        $subId = bin2hex(random_bytes(8));
        $client = [
            'id' => $clientUuid,
            'flow' => (string) ($config['default_flow'] ?? ''),
            'email' => $email,
            'limitIp' => 0,
            'totalGB' => $this->gbToBytes($request->trafficLimitGb),
            'expiryTime' => $this->durationToMs($request->durationDays),
            'enable' => true,
            'tgId' => '',
            'subId' => $subId,
            'reset' => 0,
            'security' => (string) ($inbound->getSecurity() ?? 'reality'),
            'network' => (string) ($inbound->getNetwork() ?? 'tcp'),
        ];

        $result = $this->apiClient->addClient($panel, $inboundId, $client);
        $this->assertPanelResult($result, 'addClient');
        $this->assertPanelBusinessResult($result, 'addClient', true);

        $subscriptionUrl = $this->buildSubscriptionUrl($config, $subId, $email);
        $configText = self::CONFIG_TEXT_WITH_SUBSCRIPTION;
        if (null === $subscriptionUrl) {
            $this->log(sprintf('subscription_url_missing panel_id=%s email="%s"', $panel->getId() ?? 'null', $email));
            $configText = self::CONFIG_TEXT_SUBSCRIPTION_UNAVAILABLE;
        }

        return new CreatedVpnService(
            remoteId: $this->remoteIdParser->format($panel->getId(), $inbound->getId(), $inboundId, $clientUuid, $email),
            username: $email,
            subscriptionUrl: $subscriptionUrl,
            configText: $configText,
        );
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $client = $this->fetchClientByReference($panel, $ref);
        $client['enable'] = false;

        $result = $this->apiClient->updateClient($panel, $ref->inboundId, $ref->clientId, $client);
        $this->assertPanelResult($result, 'updateClient');
        $this->assertPanelBusinessResult($result, 'updateClient', true);
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $client = $this->fetchClientByReference($panel, $ref);
        $client['enable'] = true;

        if ($request->durationDays > 0) {
            $client['expiryTime'] = $this->durationToMs($request->durationDays);
        }

        if (null !== $request->trafficLimitGb) {
            $client['totalGB'] = $this->gbToBytes($request->trafficLimitGb);
        }

        $result = $this->apiClient->updateClient($panel, $ref->inboundId, $ref->clientId, $client);
        $this->assertPanelResult($result, 'updateClient');
        $this->assertPanelBusinessResult($result, 'updateClient', true);
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $result = $this->apiClient->deleteClient($panel, $ref->inboundId, $ref->clientId);
        $this->assertPanelResult($result, 'delClient');
        $this->assertPanelBusinessResult($result, 'delClient');
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $result = $this->apiClient->resetClientTraffic($panel, $ref->inboundId, $ref->email);
        $this->assertPanelResult($result, 'resetClientTraffic');
        $this->assertPanelBusinessResult($result, 'resetClientTraffic');
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $result = $this->apiClient->getClientTraffic($panel, $ref->email);
        $this->assertPanelResult($result, 'getClientTraffics');
        $this->assertPanelBusinessResult($result, 'getClientTraffics');

        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $obj = is_array($payload['obj'] ?? null) ? $payload['obj'] : $payload;

        $upBytes = (int) ($obj['up'] ?? 0);
        $downBytes = (int) ($obj['down'] ?? 0);
        $totalBytes = (int) ($obj['total'] ?? ($obj['totalGB'] ?? 0));

        return new VpnUsage(
            trafficUsedGb: (int) floor(($upBytes + $downBytes) / 1073741824),
            trafficLimitGb: $totalBytes > 0 ? (int) floor($totalBytes / 1073741824) : null,
        );
    }

    private function requireSupportedPanel(?VpnPanel $panel): VpnPanel
    {
        if (!$panel instanceof VpnPanel || 'sanaei_3xui' !== $panel->getType()) {
            throw new \RuntimeException('Requested panel is not a Sanaei/3x-ui panel.');
        }

        return $panel;
    }

    private function parseRemoteIdOrFail(string $remoteId): Sanaei3xuiRemoteClientRef
    {
        $parsed = $this->remoteIdParser->parse($remoteId);
        if (!$parsed instanceof Sanaei3xuiRemoteClientRef) {
            $this->log(sprintf('remote_id_parse_failure remote_id="%s"', $remoteId));
            throw new \RuntimeException('Unable to parse service remote id for Sanaei panel.');
        }

        return $parsed;
    }

    private function panelConfig(VpnPanel $panel): array
    {
        return is_array($panel->getConfig()) ? $panel->getConfig() : [];
    }

    private function fetchClientByReference(VpnPanel $panel, Sanaei3xuiRemoteClientRef $ref): array
    {
        $result = $this->apiClient->getInbound($panel, $ref->inboundId);
        $this->assertPanelResult($result, 'getInbound');
        $this->assertPanelBusinessResult($result, 'getInbound');

        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $inbound = is_array($payload['obj'] ?? null) ? $payload['obj'] : [];
        $settingsRaw = $inbound['settings'] ?? null;
        $settings = [];
        if (is_string($settingsRaw) && '' !== trim($settingsRaw)) {
            $decodedSettings = json_decode($settingsRaw, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decodedSettings)) {
                $settings = $decodedSettings;
            }
        } elseif (is_array($settingsRaw)) {
            $settings = $settingsRaw;
        }

        foreach (($settings['clients'] ?? []) as $client) {
            if (!is_array($client)) {
                continue;
            }

            $currentId = (string) ($client['id'] ?? '');
            $currentEmail = (string) ($client['email'] ?? '');
            if ($currentId === $ref->clientId || $currentEmail === $ref->email) {
                return $client;
            }
        }

        throw new \RuntimeException('Client not found in inbound settings.');
    }

    private function assertPanelResult(array $result, string $operation): void
    {
        if (($result['ok'] ?? false) === true) {
            return;
        }

        throw new \RuntimeException(sprintf('Sanaei panel %s request failed.', $operation));
    }

    private function assertPanelBusinessResult(array $result, string $operation, bool $allowEmpty = false): void
    {
        if (($result['empty'] ?? false) === true) {
            if ($allowEmpty) {
                $this->log(sprintf('empty_api_response_treated_as_warning operation="%s"', $operation));

                return;
            }

            throw new \RuntimeException(sprintf('Sanaei panel %s returned an empty response.', $operation));
        }

        $payload = is_array($result['data'] ?? null) ? $result['data'] : null;
        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Sanaei panel %s returned invalid response payload.', $operation));
        }

        $businessSuccess = $payload['success'] ?? true;
        if (false === $businessSuccess) {
            throw new \RuntimeException(sprintf('Sanaei panel %s failed.', $operation));
        }
    }

    private function buildSubscriptionUrl(array $config, string $subId, string $email): ?string
    {
        $base = trim((string) ($config['subscription_base_url'] ?? ''));
        if ('' === $base) {
            return null;
        }

        $base = rtrim($base, '/');
        if ('' === $base) {
            return null;
        }

        if ('' !== trim($subId)) {
            return sprintf('%s/sub/%s', $base, rawurlencode($subId));
        }

        return sprintf('%s/sub/%s', $base, rawurlencode($email));
    }

    private function gbToBytes(?int $gb): int
    {
        if (null === $gb || $gb <= 0) {
            return 0;
        }

        return $gb * 1073741824;
    }

    private function durationToMs(int $durationDays): int
    {
        if ($durationDays <= 0) {
            return 0;
        }

        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $durationDays))->getTimestamp() * 1000;
    }

    private function log(string $message): void
    {
        error_log('[Sanaei3xuiDriver] '.$message);
    }
}
