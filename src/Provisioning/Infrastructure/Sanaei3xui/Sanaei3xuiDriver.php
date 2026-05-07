<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

use App\Entity\VpnInbound;
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

        $inboundIdRaw = trim((string) ($request->remoteInboundId ?? $inbound->getRemoteInboundId()));
        if ('' === $inboundIdRaw) {
            throw new \RuntimeException('Sanaei provisioning requires a valid remote inbound id.');
        }
        if (!preg_match('/^\d+$/', $inboundIdRaw)) {
            throw new \RuntimeException('Remote inbound id must be numeric for Sanaei/3x-ui.');
        }
        $inboundIdInt = (int) $inboundIdRaw;

        $email = trim((string) $request->username);
        if ('' === $email) {
            throw new \RuntimeException('Sanaei provisioning requires a valid email/username.');
        }
        $clientUuid = Uuid::v4()->toRfc4122();
        $subId = bin2hex(random_bytes(8));
        $totalBytes = $this->gbToBytes($request->trafficLimitGb);
        $expiryTime = $this->durationToMs($request->durationDays);

        $protocol = $this->resolveInboundProtocol($inbound);
        $network = (string) ($inbound->getNetwork() ?? '');
        $security = (string) ($inbound->getSecurity() ?? '');
        $client = $this->buildClientPayload($protocol, $clientUuid, $email, $subId, $totalBytes, $expiryTime, $config);
        $source = trim((string) ($request->meta['source'] ?? ''));
        $orderId = (int) ($request->meta['orderId'] ?? 0);
        $paymentId = (int) ($request->meta['paymentId'] ?? 0);
        $planId = (int) ($request->meta['planId'] ?? 0);
        $planInboundId = (int) ($request->meta['planInboundId'] ?? ($inbound->getId() ?? 0));
        $driverType = trim((string) ($request->meta['driverType'] ?? $panel->getType()));

        $this->log(sprintf(
            'payment_approval_provision_context source="%s" order_id=%d payment_id=%d plan_id=%d plan_inbound_id=%d remote_inbound_id_raw="%s" remote_inbound_id_int=%d panel_id=%d driver_type="%s"',
            $source,
            $orderId,
            $paymentId,
            $planId,
            $planInboundId,
            $inboundIdRaw,
            $inboundIdInt,
            $panel->getId() ?? 0,
            $driverType
        ));

        $this->log(sprintf(
            'create_service_add_client_request panel_id=%s local_inbound_id=%s remote_inbound_id_raw="%s" remote_inbound_id_int=%d protocol="%s" network="%s" security="%s" uuid="%s" email="%s" total_gb_bytes=%d expiry_time=%d',
            $panel->getId() ?? 'null',
            $inbound->getId() ?? 'null',
            $inboundIdRaw,
            $inboundIdInt,
            $protocol,
            $network,
            $security,
            $clientUuid,
            $email,
            $totalBytes,
            $expiryTime
        ));

        $result = $this->apiClient->addClient($panel, $inboundIdInt, $client, [
            'localInboundId' => (string) ($inbound->getId() ?? 0),
            'remoteInboundIdRaw' => $inboundIdRaw,
            'remoteInboundIdInt' => (string) $inboundIdInt,
            'protocol' => $protocol,
            'network' => $network,
            'security' => $security,
            'clientUuid' => $clientUuid,
            'email' => $email,
            'totalGB' => (string) $totalBytes,
            'expiryTime' => (string) $expiryTime,
        ]);
        $this->log(sprintf(
            'create_service_add_client_response panel_id=%s local_inbound_id=%s remote_inbound_id_raw="%s" remote_inbound_id_int=%d status=%s ok=%s success=%s empty=%s error="%s" body_preview="%s"',
            $panel->getId() ?? 'null',
            $inbound->getId() ?? 'null',
            $inboundIdRaw,
            $inboundIdInt,
            (string) ($result['status'] ?? 'null'),
            (($result['ok'] ?? false) === true) ? 'true' : 'false',
            (($result['success'] ?? false) === true) ? 'true' : 'false',
            (($result['empty'] ?? false) === true) ? 'true' : 'false',
            (string) ($result['error'] ?? ''),
            (string) ($result['bodyPreview'] ?? '')
        ));

        $this->assertPanelResult($result, 'addClient');
        if (($result['empty'] ?? false) !== true) {
            $this->assertPanelBusinessResult($result, 'addClient');
        }

        if (!$this->verifyClientExists($panel, $inboundIdRaw, $email)) {
            throw new \RuntimeException('Sanaei addClient could not be verified on panel.');
        }

        $subscriptionUrl = $this->buildSubscriptionUrl($config, $subId, $email);
        $configText = self::CONFIG_TEXT_WITH_SUBSCRIPTION;
        if (null === $subscriptionUrl) {
            $this->log(sprintf('subscription_url_missing panel_id=%s email="%s"', $panel->getId() ?? 'null', $email));
            $configText = self::CONFIG_TEXT_SUBSCRIPTION_UNAVAILABLE;
        }

        return new CreatedVpnService(
            remoteId: $this->remoteIdParser->format($panel->getId(), $inbound->getId(), $inboundIdRaw, $clientUuid, $email, $subId),
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
            $msg = trim((string) ($payload['msg'] ?? ''));
            if ('' === $msg) {
                throw new \RuntimeException(sprintf('Sanaei panel %s failed.', $operation));
            }

            throw new \RuntimeException(sprintf('Sanaei panel %s failed: %s', $operation, $msg));
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

    /**
     * @return array<string, mixed>
     */
    private function buildClientPayload(string $protocol, string $clientUuid, string $email, string $subId, int $totalBytes, int $expiryTime, array $panelConfig): array
    {
        $normalized = strtolower(trim($protocol));
        if ('trojan' === $normalized) {
            throw new \RuntimeException('Trojan protocol is not currently supported. Please use VLESS or VMess inbounds.');
        }

        $client = [
            'id' => $clientUuid,
            'email' => $email,
            'enable' => true,
            'totalGB' => $totalBytes,
            'expiryTime' => $expiryTime,
            'tgId' => '',
            'subId' => $subId,
        ];

        if ('vless' === $normalized) {
            $client['flow'] = (string) ($panelConfig['default_flow'] ?? '');
        }

        return $client;
    }

    private function resolveInboundProtocol(VpnInbound $inbound): string
    {
        $protocol = trim((string) ($inbound->getProtocol() ?? ''));
        if ('' !== $protocol) {
            return strtolower($protocol);
        }

        $config = $inbound->getConfig();
        if (is_array($config)) {
            $candidate = trim((string) ($config['protocol'] ?? (($config['raw']['protocol'] ?? null))));
            if ('' !== $candidate) {
                return strtolower($candidate);
            }
        }

        return 'vless';
    }

    private function verifyClientExists(VpnPanel $panel, string $remoteInboundId, string $email): bool
    {
        $trafficResult = $this->apiClient->getClientTraffic($panel, $email);
        if (($trafficResult['ok'] ?? false) === true && ($trafficResult['empty'] ?? false) !== true) {
            $payload = is_array($trafficResult['data'] ?? null) ? $trafficResult['data'] : [];
            if (($payload['success'] ?? true) !== false) {
                $obj = $payload['obj'] ?? null;
                if (is_array($obj) || is_numeric($obj) || is_string($obj)) {
                    $this->log(sprintf('add_client_verified_by_traffic email="%s"', $email));

                    return true;
                }
            }
        }

        $inboundResult = $this->apiClient->getInbound($panel, $remoteInboundId);
        if (($inboundResult['ok'] ?? false) !== true || ($inboundResult['empty'] ?? false) === true) {
            $this->log(sprintf(
                'add_client_verify_get_inbound_failed remote_inbound_id="%s" status=%s ok=%s body_preview="%s"',
                $remoteInboundId,
                (string) ($inboundResult['status'] ?? 'null'),
                (($inboundResult['ok'] ?? false) === true) ? 'true' : 'false',
                (string) ($inboundResult['bodyPreview'] ?? '')
            ));

            return false;
        }

        $payload = is_array($inboundResult['data'] ?? null) ? $inboundResult['data'] : [];
        if (($payload['success'] ?? true) === false) {
            $this->log(sprintf(
                'add_client_verify_get_inbound_business_failed remote_inbound_id="%s" msg="%s"',
                $remoteInboundId,
                (string) ($payload['msg'] ?? '')
            ));

            return false;
        }

        $obj = is_array($payload['obj'] ?? null) ? $payload['obj'] : $payload;
        $settings = $this->decodeSettings($obj['settings'] ?? null);
        foreach (($settings['clients'] ?? []) as $client) {
            if (!is_array($client)) {
                continue;
            }

            if (trim((string) ($client['email'] ?? '')) === $email) {
                $this->log(sprintf('add_client_verified_by_inbound remote_inbound_id="%s" email="%s"', $remoteInboundId, $email));

                return true;
            }
        }

        $this->log(sprintf(
            'add_client_verify_not_found remote_inbound_id="%s" email="%s" body_preview="%s"',
            $remoteInboundId,
            $email,
            (string) ($inboundResult['bodyPreview'] ?? '')
        ));

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSettings(mixed $settingsRaw): array
    {
        if (is_array($settingsRaw)) {
            return $settingsRaw;
        }

        if (!is_string($settingsRaw) || '' === trim($settingsRaw)) {
            return [];
        }

        $decoded = json_decode($settingsRaw, true);

        return (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) ? $decoded : [];
    }
}
