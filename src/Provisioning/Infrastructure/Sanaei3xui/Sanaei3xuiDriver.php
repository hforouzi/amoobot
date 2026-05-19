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
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly Sanaei3xuiApiClient $apiClient,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly Sanaei3xuiConfigGenerator $configGenerator,
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
        $inboundIdInt = $this->toInboundIdIntOrFail($inboundIdRaw);

        $email = trim((string) $request->username);
        if ('' === $email) {
            throw new \RuntimeException('Sanaei provisioning requires a valid email/username.');
        }
        $clientUuid = Uuid::v4()->toRfc4122();
        $subId = bin2hex(random_bytes(8));
        $totalBytes = $this->gbToBytes($request->trafficLimitGb);
        $expiryTime = $this->durationToMs($request->durationDays);
        $ipLimit = $request->ipLimit;

        $protocol = $this->resolveInboundProtocol($inbound);
        $network = (string) ($inbound->getNetwork() ?? '');
        $security = (string) ($inbound->getSecurity() ?? '');
        $client = $this->buildClientPayload($protocol, $clientUuid, $email, $subId, $totalBytes, $expiryTime, $config, $ipLimit);
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
            'generated_sub_id panel_id=%d inbound_id=%d sub_id="%s"',
            $panel->getId() ?? 0,
            $inbound->getId() ?? 0,
            $subId
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
            'subId' => $subId,
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

        $subscriptionUrl = $this->buildSubscriptionUrl($panel, $config, $subId);
        $configLinks = [];
        $configText = '';

        if ($this->isV3Panel($panel)) {
            $officialClientLinksResult = $this->apiClient->getClientLinks($panel, $inboundIdInt, $email);
            if (($officialClientLinksResult['ok'] ?? false) === true) {
                $payload = is_array($officialClientLinksResult['data'] ?? null) ? $officialClientLinksResult['data'] : [];
                if (($payload['success'] ?? false) === true) {
                    $configLinks = $this->extractLinkArray($payload['obj'] ?? null);
                }
            }

            if ('' !== trim($subId)) {
                $subLinksResult = $this->apiClient->getSubLinks($panel, $subId);
                if (($subLinksResult['ok'] ?? false) === true) {
                    $subPayload = is_array($subLinksResult['data'] ?? null) ? $subLinksResult['data'] : [];
                    if (($subPayload['success'] ?? false) === true) {
                        $configLinks = array_values(array_unique([
                            ...$configLinks,
                            ...$this->extractLinkArray($subPayload['obj'] ?? null),
                        ]));
                    }
                }
            }
        }

        $generatedConfigText = trim($this->configGenerator->generateConfigText($inbound, $clientUuid, $email, $subId));
        $generatedConfigLinks = array_values(array_filter(
            array_map('trim', explode("\n", $generatedConfigText)),
            static fn (string $line): bool => '' !== $line
        ));

        if ([] === $configLinks && [] !== $generatedConfigLinks && $this->hasExternalProxyConfig($inboundConfig)) {
            $configText = $generatedConfigText;
            $configLinks = $generatedConfigLinks;
        } elseif ([] === $configLinks) {
            $configText = $generatedConfigText;
            $configLinks = $generatedConfigLinks;
        } else {
            $configText = implode("\n", $configLinks);
        }

        $this->log(sprintf(
            'create_service_generated_config source="%s" inbound_id=%d uuid="%s" sub_id="%s" generated_config_link_count=%d config_text_empty=%s subscription_url_present=%s config_text_preview="%s"',
            $source,
            $inbound->getId() ?? 0,
            $clientUuid,
            $subId,
            count($configLinks),
            '' === $configText ? 'yes' : 'no',
            '' !== trim((string) ($subscriptionUrl ?? '')) ? 'yes' : 'no',
            $this->sanitizeLogPreview($configText)
        ));

        return new CreatedVpnService(
            remoteId: $this->remoteIdParser->format($panel->getId(), $inbound->getId(), $inboundIdRaw, $clientUuid, $email, $subId),
            username: $email,
            subscriptionUrl: $subscriptionUrl,
            configText: '' !== $configText ? $configText : null,
            clientUuid: $clientUuid,
            clientEmail: $email,
            subId: $subId,
            ipLimit: $ipLimit,
            configLinks: $configLinks,
        );
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $client = $this->fetchClientByReference($panel, $ref);
        $client['enable'] = false;

        $inboundIdRaw = trim($ref->inboundId);
        $inboundIdInt = $this->toInboundIdIntOrFail($inboundIdRaw);

        $result = $this->apiClient->updateClient($panel, $inboundIdInt, $ref->clientId, $client, [
            'remoteInboundIdRaw' => $inboundIdRaw,
            'remoteInboundIdInt' => (string) $inboundIdInt,
            'clientUuid' => $ref->clientId,
            'email' => $ref->email,
        ]);
        $this->assertPanelResult($result, 'updateClient');
        $this->assertPanelBusinessResult($result, 'updateClient', true);
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $client = $this->fetchClientByReference($panel, $ref);
        $client['enable'] = true;

        if ($request->unlimitedDuration) {
            $client['expiryTime'] = 0;
        } elseif ($request->expiresAt instanceof \DateTimeImmutable) {
            $client['expiryTime'] = $request->expiresAt->getTimestamp() * 1000;
        } elseif ($request->durationDays > 0) {
            $client['expiryTime'] = $this->durationToMs($request->durationDays);
        }

        if (null !== $request->trafficLimitGb) {
            $client['totalGB'] = $this->gbToBytes($request->trafficLimitGb);
        }

        $inboundIdRaw = trim($ref->inboundId);
        $inboundIdInt = $this->toInboundIdIntOrFail($inboundIdRaw);
        $serviceId = $request->serviceId;
        $orderId = $request->orderId;
        $serviceIdLabel = null === $serviceId ? '-' : (string) $serviceId;
        $orderIdLabel = null === $orderId ? '-' : (string) $orderId;

        $this->log(sprintf(
            'renew_update_client_context panel_id=%s remote_inbound_id_raw="%s" remote_inbound_id_int=%d client_uuid="%s" email="%s" service_id=%s order_id=%s',
            $panel->getId() ?? 'null',
            $inboundIdRaw,
            $inboundIdInt,
            $ref->clientId,
            $ref->email,
            $serviceIdLabel,
            $orderIdLabel
        ));

        $context = [
            'remoteInboundIdRaw' => $inboundIdRaw,
            'remoteInboundIdInt' => (string) $inboundIdInt,
            'clientUuid' => $ref->clientId,
            'email' => $ref->email,
        ];
        if (null !== $serviceId) {
            $context['serviceId'] = (string) $serviceId;
        }
        if (null !== $orderId) {
            $context['orderId'] = (string) $orderId;
        }

        $result = $this->apiClient->updateClient($panel, $inboundIdInt, $ref->clientId, $client, $context);
        $this->assertPanelResult($result, 'updateClient');
        $this->assertPanelBusinessResult($result, 'updateClient', true);

        try {
            $verifyResult = $this->apiClient->getClientTraffic($panel, $ref->email);
            $this->assertPanelResult($verifyResult, 'getClientTraffic');
            $this->assertPanelBusinessResult($verifyResult, 'getClientTraffic');
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'renew_verify_warning panel_id=%s email="%s" message="%s"',
                $panel->getId() ?? 'null',
                $ref->email,
                $this->sanitizeLogPreview($e->getMessage())
            ));
        }
    }

    public function addTrafficLimit(string $remoteId, int $trafficLimitGb, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $client = $this->fetchClientByReference($panel, $ref);
        $client['totalGB'] = $this->gbToBytes($trafficLimitGb);

        $inboundIdRaw = trim($ref->inboundId);
        $inboundIdInt = $this->toInboundIdIntOrFail($inboundIdRaw);
        $result = $this->apiClient->updateClient($panel, $inboundIdInt, $ref->clientId, $client, [
            'remoteInboundIdRaw' => $inboundIdRaw,
            'remoteInboundIdInt' => (string) $inboundIdInt,
            'clientUuid' => $ref->clientId,
            'email' => $ref->email,
            'mode' => 'add_traffic',
        ]);
        $this->assertPanelResult($result, 'updateClient');
        $this->assertPanelBusinessResult($result, 'updateClient', true);

        try {
            $verifyResult = $this->apiClient->getClientTraffic($panel, $ref->email);
            $this->assertPanelResult($verifyResult, 'getClientTraffic');
            $this->assertPanelBusinessResult($verifyResult, 'getClientTraffic');

            $payload = is_array($verifyResult['data'] ?? null) ? $verifyResult['data'] : [];
            $obj = is_array($payload['obj'] ?? null) ? $payload['obj'] : [];
            $remoteTotal = $this->toNonNegativeInt($obj['total'] ?? ($obj['totalGB'] ?? null));
            $expectedTotal = $this->gbToBytes($trafficLimitGb);
            if (null !== $remoteTotal && abs($remoteTotal - $expectedTotal) > self::BYTES_PER_GB) {
                $this->log(sprintf(
                    'add_traffic_verify_warning panel_id=%s email="%s" expected_total=%d remote_total=%d',
                    $panel->getId() ?? 'null',
                    $ref->email,
                    $expectedTotal,
                    $remoteTotal
                ));
            }
        } catch (\Throwable $e) {
            $this->log(sprintf(
                'add_traffic_verify_warning panel_id=%s email="%s" message="%s"',
                $panel->getId() ?? 'null',
                $ref->email,
                $this->sanitizeLogPreview($e->getMessage())
            ));
        }
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $inboundIdInt = $this->toInboundIdIntOrFail($ref->inboundId);
        $result = $this->apiClient->deleteClient($panel, (string) $inboundIdInt, $ref->clientId);
        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($this->isV3Panel($panel) && ((($result['ok'] ?? false) !== true) || (($payload['success'] ?? true) === false))) {
            $result = $this->apiClient->deleteClientByEmail($panel, (string) $inboundIdInt, $ref->email);
        }
        $this->assertPanelResult($result, 'delClient');
        $this->assertPanelBusinessResult($result, 'delClient');
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $inboundIdInt = $this->toInboundIdIntOrFail($ref->inboundId);
        $result = $this->apiClient->resetClientTraffic($panel, (string) $inboundIdInt, $ref->email);
        $this->assertPanelResult($result, 'resetClientTraffic');
        $this->assertPanelBusinessResult($result, 'resetClientTraffic');
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        $panel = $this->requireSupportedPanel($panel);
        $ref = $this->parseRemoteIdOrFail($remoteId);
        $result = $this->apiClient->getClientTraffic($panel, $ref->email);
        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $obj = $payload['obj'] ?? null;
        $missingObj = null === $obj || ([] === $obj);
        if ($this->isV3Panel($panel) && (($result['ok'] ?? false) !== true || (($payload['success'] ?? true) === false) || $missingObj)) {
            $fallbackId = trim($ref->clientId);
            if ('' === $fallbackId) {
                $fallbackId = trim((string) ($ref->subId ?? ''));
            }
            if ('' !== $fallbackId) {
                $result = $this->apiClient->getClientTrafficById($panel, $fallbackId);
            }
        }
        $this->assertPanelResult($result, 'getClientTraffics');
        $this->assertPanelBusinessResult($result, 'getClientTraffics');

        $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
        $obj = is_array($payload['obj'] ?? null) ? $payload['obj'] : $payload;
        if ([] === $obj) {
            $this->log(sprintf('usage_sync_warning panel_id=%s email="%s" reason="missing_usage_payload"', $panel->getId() ?? 'null', $ref->email));
        }

        $upBytes = $this->toNonNegativeInt($obj['up'] ?? null);
        $downBytes = $this->toNonNegativeInt($obj['down'] ?? null);
        $allTimeBytes = $this->toNonNegativeInt($obj['allTime'] ?? null);
        $totalBytes = $this->toNonNegativeInt($obj['total'] ?? ($obj['totalGB'] ?? null));
        $expiryTimeRaw = $this->toNonNegativeInt($obj['expiryTime'] ?? null);
        $isEnabled = isset($obj['enable']) ? (bool) $obj['enable'] : null;

        $usedBytes = null;
        if (null !== $allTimeBytes) {
            $usedBytes = $allTimeBytes;
        } elseif (null !== $upBytes || null !== $downBytes) {
            $usedBytes = max(0, (int) (($upBytes ?? 0) + ($downBytes ?? 0)));
        } else {
            $this->log(sprintf('usage_sync_warning panel_id=%s email="%s" reason="missing_up_down_all_time"', $panel->getId() ?? 'null', $ref->email));
        }

        $expiresAt = null;
        if (null !== $expiryTimeRaw && $expiryTimeRaw > 0) {
            $expiresAt = (new \DateTimeImmutable())->setTimestamp((int) floor($expiryTimeRaw / 1000));
        } elseif (array_key_exists('expiryTime', $obj) && (null === $expiryTimeRaw || $expiryTimeRaw <= 0)) {
            $this->log(sprintf('usage_sync_warning panel_id=%s email="%s" reason="expiry_non_positive"', $panel->getId() ?? 'null', $ref->email));
        }

        if (null === $totalBytes && !array_key_exists('total', $obj) && !array_key_exists('totalGB', $obj)) {
            $this->log(sprintf('usage_sync_warning panel_id=%s email="%s" reason="missing_total_bytes"', $panel->getId() ?? 'null', $ref->email));
        }

        return new VpnUsage(
            trafficUsedGb: null !== $usedBytes ? $this->bytesToGb($usedBytes) : null,
            trafficLimitGb: null !== $totalBytes && $totalBytes > 0 ? $this->bytesToGb($totalBytes) : null,
            usedBytes: $usedBytes,
            totalBytes: null !== $totalBytes && $totalBytes > 0 ? $totalBytes : null,
            expiresAt: $expiresAt,
            isEnabled: $isEnabled,
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

    private function buildSubscriptionUrl(VpnPanel $panel, array $config, string $subId): ?string
    {
        $base = trim((string) ($config['subscription_base_url'] ?? ''));
        if ('' === $base) {
            $base = trim((string) ($panel->getSubscriptionBaseUrl() ?? ''));
        }
        $prefix = trim((string) ($config['subscription_path_prefix'] ?? ''));

        $this->log(sprintf(
            'subscription_url_inputs panel_id=%s subscription_base_url_exists=%s subscription_path_prefix="%s" sub_id_present=%s',
            $panel->getId() ?? 'null',
            '' !== $base ? 'yes' : 'no',
            $prefix,
            '' !== trim($subId) ? 'yes' : 'no'
        ));

        if ('' === trim($subId)) {
            $this->log(sprintf('subscription_url_missing_sub_id panel_id=%s', $panel->getId() ?? 'null'));

            return null;
        }

        if ('' === $base) {
            $this->log(sprintf('subscription_url_missing_base panel_id=%s', $panel->getId() ?? 'null'));
            return null;
        }

        $base = rtrim($base, '/');
        if ('' === $base) {
            return null;
        }
        if (false === filter_var($base, FILTER_VALIDATE_URL)) {
            $this->log(sprintf('subscription_url_invalid_base panel_id=%s base="%s"', $panel->getId() ?? 'null', $base));

            return null;
        }

        if ('' === $prefix) {
            $prefix = '/sub';
        }
        $prefix = '/'.trim($prefix, '/');
        $url = sprintf('%s%s/%s', $base, $prefix, rawurlencode($subId));
        $url = preg_replace('#(?<!:)/{2,}#', '/', $url) ?? $url;

        $this->log(sprintf(
            'subscription_url_generated panel_id=%s generated=%s url="%s"',
            $panel->getId() ?? 'null',
            '' !== trim((string) $url) ? 'yes' : 'no',
            (string) $url
        ));

        return '' === trim((string) $url) ? null : $url;
    }

    private function gbToBytes(?int $gb): int
    {
        if (null === $gb || $gb <= 0) {
            return 0;
        }

        return $gb * self::BYTES_PER_GB;
    }

    private function bytesToGb(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        return (int) floor($bytes / self::BYTES_PER_GB);
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

    private function toNonNegativeInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= 0 ? $number : null;
    }

    private function toInboundIdIntOrFail(string $inboundIdRaw): int
    {
        $trimmed = trim($inboundIdRaw);
        if ('' === $trimmed || !preg_match('/^\d+$/', $trimmed)) {
            throw new \RuntimeException('Remote inbound id must be numeric for Sanaei/3x-ui.');
        }

        return (int) $trimmed;
    }

    private function sanitizeLogPreview(string $value, int $max = 120): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ('' === $text) {
            return '';
        }

        return mb_substr($text, 0, $max);
    }

    private function isV3Panel(VpnPanel $panel): bool
    {
        return 'v3' === strtolower(trim((string) ($this->panelConfig($panel)['api_version'] ?? $panel->getApiVersion() ?? 'legacy')));
    }

    /**
     * @return list<string>
     */
    private function extractLinkArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $links = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ('' === $text) {
                continue;
            }
            $links[] = $text;
        }

        return array_values(array_unique($links));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientPayload(string $protocol, string $clientUuid, string $email, string $subId, int $totalBytes, int $expiryTime, array $panelConfig, ?int $ipLimit): array
    {
        $normalized = strtolower(trim($protocol));
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
        if (null !== $ipLimit) {
            $client['limitIp'] = max(0, (int) $ipLimit);
        }
        if ('trojan' === $normalized) {
            $client['password'] = $clientUuid;
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

    /**
     * @return array<string, mixed>
     */
    private function inboundConfig(VpnInbound $inbound): array
    {
        $config = $inbound->getConfig();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $panelConfig
     * @param array<string, mixed> $inboundConfig
     * @param array<string, mixed> $clientPayload
     */
    private function buildClientConfigText(string $protocol, VpnInbound $inbound, VpnPanel $panel, array $panelConfig, array $inboundConfig, string $clientUuid, string $email, string $subId, ?string $subscriptionUrl, array $clientPayload): string
    {
        $protocol = strtolower(trim($protocol));
        $summary = [];
        if (null !== $subscriptionUrl) {
            $summary[] = '🔗 لینک اشتراک:';
            $summary[] = $subscriptionUrl;
            $summary[] = '';
        }

        $configUrl = match ($protocol) {
            'vless' => $this->buildVlessUrl($inbound, $panel, $panelConfig, $inboundConfig, $clientUuid, $email, $subId, $clientPayload),
            'vmess' => $this->buildVmessUrl($inbound, $panel, $panelConfig, $inboundConfig, $clientUuid, $email, $clientPayload),
            'trojan' => $this->buildTrojanUrl($inbound, $panel, $panelConfig, $inboundConfig, $clientUuid, $email),
            default => null,
        };

        if (null === $configUrl) {
            $summary[] = '⚠️ کانفیگ قابل تولید نیست. تنظیمات اینباند/پنل را بررسی کنید.';

            return implode("\n", $summary);
        }

        $summary[] = '📡 کانفیگ:';
        $summary[] = $configUrl;

        return implode("\n", $summary);
    }

    /**
     * @param array<string, mixed> $panelConfig
     * @param array<string, mixed> $inboundConfig
     * @param array<string, mixed> $clientPayload
     */
    private function buildVlessUrl(VpnInbound $inbound, VpnPanel $panel, array $panelConfig, array $inboundConfig, string $clientUuid, string $email, string $subId, array $clientPayload): ?string
    {
        $host = $this->resolvePublicHost($panel, $panelConfig, $inboundConfig);
        $port = $this->resolveInboundPort($inboundConfig);
        if ('' === $host || null === $port) {
            return null;
        }

        $stream = $this->toArray($inboundConfig['streamSettings'] ?? []);
        $network = trim((string) ($stream['network'] ?? $inbound->getNetwork() ?? 'tcp'));
        if ('' === $network) {
            $network = 'tcp';
        }
        $security = trim((string) ($stream['security'] ?? $inbound->getSecurity() ?? 'none'));
        if ('' === $security || 'none' === strtolower($security)) {
            $security = 'none';
        }

        $query = [
            'type' => strtolower($network),
            'security' => strtolower($security),
            'encryption' => 'none',
        ];

        $flow = trim((string) ($clientPayload['flow'] ?? ''));
        if ('' !== $flow) {
            $query['flow'] = $flow;
        }

        $tls = $this->toArray($stream['tlsSettings'] ?? ($inboundConfig['tlsSettings'] ?? []));
        $reality = $this->toArray($stream['realitySettings'] ?? ($inboundConfig['realitySettings'] ?? []));
        $ws = $this->toArray($stream['wsSettings'] ?? ($inboundConfig['wsSettings'] ?? []));
        $grpc = $this->toArray($stream['grpcSettings'] ?? ($inboundConfig['grpcSettings'] ?? []));

        if ('tls' === strtolower($security)) {
            $serverName = trim((string) ($tls['serverName'] ?? ''));
            if ('' !== $serverName) {
                $query['sni'] = $serverName;
            }
            $alpn = $tls['alpn'] ?? null;
            if (is_array($alpn) && [] !== $alpn) {
                $query['alpn'] = implode(',', array_map('strval', $alpn));
            }
        }

        if ('reality' === strtolower($security)) {
            $publicKey = trim((string) ($reality['settings']['publicKey'] ?? $reality['publicKey'] ?? ''));
            if ('' !== $publicKey) {
                $query['pbk'] = $publicKey;
            }
            $fingerprint = trim((string) ($reality['settings']['fingerprint'] ?? $reality['fingerprint'] ?? ''));
            if ('' !== $fingerprint) {
                $query['fp'] = $fingerprint;
            }
            $serverNames = $reality['serverNames'] ?? [];
            if (is_array($serverNames) && [] !== $serverNames) {
                $query['sni'] = (string) ($serverNames[0] ?? '');
            }
            $shortIds = $reality['shortIds'] ?? [];
            if (is_array($shortIds) && [] !== $shortIds) {
                $shortId = trim((string) ($shortIds[0] ?? ''));
                if ('' !== $shortId) {
                    $query['sid'] = $shortId;
                }
            }
            $spiderX = trim((string) ($reality['settings']['spiderX'] ?? $reality['spiderX'] ?? ''));
            if ('' !== $spiderX) {
                $query['spx'] = $spiderX;
            }
        }

        if ('ws' === strtolower($network)) {
            $path = trim((string) ($ws['path'] ?? '/'));
            $headers = $this->toArray($ws['headers'] ?? []);
            $hostHeader = trim((string) ($headers['Host'] ?? $headers['host'] ?? ''));
            if ('' !== $hostHeader) {
                $query['host'] = $hostHeader;
            }
            $query['path'] = '' !== $path ? $path : '/';
        }

        if ('grpc' === strtolower($network)) {
            $serviceName = trim((string) ($grpc['serviceName'] ?? ''));
            if ('' !== $serviceName) {
                $query['serviceName'] = $serviceName;
            }
            $mode = trim((string) ($grpc['multiMode'] ?? ''));
            if ('' !== $mode) {
                $query['mode'] = $mode;
            }
        }

        $queryString = http_build_query(array_filter($query, static fn ($v): bool => '' !== trim((string) $v)), '', '&', PHP_QUERY_RFC3986);
        $fragment = rawurlencode($this->safeLinkName($inbound, $email));

        return sprintf('vless://%s@%s:%d?%s#%s', rawurlencode($clientUuid), $host, $port, $queryString, $fragment);
    }

    /**
     * @param array<string, mixed> $panelConfig
     * @param array<string, mixed> $inboundConfig
     * @param array<string, mixed> $clientPayload
     */
    private function buildVmessUrl(VpnInbound $inbound, VpnPanel $panel, array $panelConfig, array $inboundConfig, string $clientUuid, string $email, array $clientPayload): ?string
    {
        $host = $this->resolvePublicHost($panel, $panelConfig, $inboundConfig);
        $port = $this->resolveInboundPort($inboundConfig);
        if ('' === $host || null === $port) {
            return null;
        }

        $stream = $this->toArray($inboundConfig['streamSettings'] ?? []);
        $network = trim((string) ($stream['network'] ?? $inbound->getNetwork() ?? 'tcp'));
        if ('' === $network) {
            $network = 'tcp';
        }
        $security = trim((string) ($stream['security'] ?? $inbound->getSecurity() ?? ''));
        $tls = $this->toArray($stream['tlsSettings'] ?? ($inboundConfig['tlsSettings'] ?? []));
        $ws = $this->toArray($stream['wsSettings'] ?? ($inboundConfig['wsSettings'] ?? []));

        $vmess = [
            'v' => '2',
            'ps' => $this->safeLinkName($inbound, $email),
            'add' => $host,
            'port' => (string) $port,
            'id' => $clientUuid,
            'aid' => '0',
            'scy' => (string) ($clientPayload['security'] ?? 'auto'),
            'net' => strtolower($network),
            'type' => 'none',
            'host' => '',
            'path' => '',
            'tls' => ('tls' === strtolower($security) || 'reality' === strtolower($security)) ? 'tls' : '',
            'sni' => trim((string) ($tls['serverName'] ?? '')),
        ];

        if ('ws' === strtolower($network)) {
            $vmess['path'] = trim((string) ($ws['path'] ?? '/'));
            $headers = $this->toArray($ws['headers'] ?? []);
            $vmess['host'] = trim((string) ($headers['Host'] ?? $headers['host'] ?? ''));
        }

        return 'vmess://'.base64_encode(json_encode($vmess, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $panelConfig
     * @param array<string, mixed> $inboundConfig
     */
    private function buildTrojanUrl(VpnInbound $inbound, VpnPanel $panel, array $panelConfig, array $inboundConfig, string $clientUuid, string $email): ?string
    {
        $host = $this->resolvePublicHost($panel, $panelConfig, $inboundConfig);
        $port = $this->resolveInboundPort($inboundConfig);
        if ('' === $host || null === $port) {
            return null;
        }

        $stream = $this->toArray($inboundConfig['streamSettings'] ?? []);
        $network = trim((string) ($stream['network'] ?? $inbound->getNetwork() ?? 'tcp'));
        if ('' === $network) {
            $network = 'tcp';
        }
        $security = trim((string) ($stream['security'] ?? $inbound->getSecurity() ?? 'tls'));
        if ('' === $security) {
            $security = 'tls';
        }

        $query = [
            'type' => strtolower($network),
            'security' => strtolower($security),
        ];

        $tls = $this->toArray($stream['tlsSettings'] ?? ($inboundConfig['tlsSettings'] ?? []));
        $serverName = trim((string) ($tls['serverName'] ?? ''));
        if ('' !== $serverName) {
            $query['sni'] = $serverName;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $fragment = rawurlencode($this->safeLinkName($inbound, $email));

        return sprintf('trojan://%s@%s:%d?%s#%s', rawurlencode($clientUuid), $host, $port, $queryString, $fragment);
    }

    /**
     * @param array<string, mixed> $panelConfig
     * @param array<string, mixed> $inboundConfig
     */
    private function resolvePublicHost(VpnPanel $panel, array $panelConfig, array $inboundConfig): string
    {
        $publicHost = trim((string) ($panelConfig['public_host'] ?? ''));
        if ('' !== $publicHost) {
            return $publicHost;
        }

        $listen = trim((string) ($inboundConfig['listen'] ?? ($inboundConfig['raw']['listen'] ?? '')));
        if ('' !== $listen && !in_array($listen, ['0.0.0.0', '::', '*'], true)) {
            return $listen;
        }

        $base = trim((string) ($panel->getBaseUrl() ?? ''));
        if ('' === $base) {
            return '';
        }
        $host = (string) (parse_url($base, PHP_URL_HOST) ?? '');

        return trim($host);
    }

    /**
     * @param array<string, mixed> $inboundConfig
     */
    private function resolveInboundPort(array $inboundConfig): ?int
    {
        $port = $inboundConfig['port'] ?? ($inboundConfig['raw']['port'] ?? null);
        if (!is_numeric($port)) {
            return null;
        }

        $normalized = (int) $port;

        return $normalized > 0 ? $normalized : null;
    }

    private function safeLinkName(VpnInbound $inbound, string $email): string
    {
        $name = trim((string) ($inbound->getRemark() ?? $inbound->getTitle()));
        if ('' === $name) {
            $name = $email;
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $inboundConfig
     */
    private function hasExternalProxyConfig(array $inboundConfig): bool
    {
        $stream = $this->toArray($inboundConfig['streamSettings'] ?? []);

        return [] !== $this->toArray($stream['externalProxy'] ?? null)
            || [] !== $this->toArray($stream['external_proxy'] ?? null)
            || [] !== $this->toArray($inboundConfig['externalProxy'] ?? null)
            || [] !== $this->toArray($inboundConfig['externalProxyList'] ?? null);
    }

    /**
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
}
