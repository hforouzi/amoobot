<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelEndpoint;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe\SanaeiCapabilityProbe;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;

/**
 * The connection test behind the admin "Test connection" button and app:panel:doctor: capability probe (stored on
 * the panel), then the inbound count, and a report of reachability, TLS, authentication, mode and latency.
 *
 * A panel confirmed modern keeps no admin username/password: they are removed (the token is all it needs).
 * The caller flushes.
 */
final class PanelConnectionTester
{
    public function __construct(
        private readonly SanaeiCapabilityProbe $probe,
        private readonly ModernClientsApi $clientsApi,
        private readonly Sanaei3xuiApiClient $legacyApiClient,
    ) {
    }

    public function test(VpnPanel $panel): PanelConnectionReport
    {
        $now = new \DateTimeImmutable();
        try {
            $endpoint = PanelEndpoint::fromPanel($panel);
            $endpoint->assertTransportAllowed();
        } catch (PanelApiException $e) {
            $panel->recordProbe(null, $e->failure->value, $now);

            return new PanelConnectionReport((string) $panel->getBaseUrl(), null, PanelConnectionReport::TLS_NOT_CHECKED, null, null, null, null, $e->failure, $e->getMessage(), $now);
        }

        $probe = $this->probe->probeAndRecord($panel);
        $tls = match (true) {
            !$endpoint->isHttps() => PanelConnectionReport::TLS_PLAIN_LOCAL,
            PanelFailure::TLS_CERTIFICATE === $probe->failure => PanelConnectionReport::TLS_FAILED,
            null !== $probe->failure && $probe->failure->isNetwork() => PanelConnectionReport::TLS_NOT_CHECKED,
            PanelFailure::NOT_CONFIGURED === $probe->failure => PanelConnectionReport::TLS_NOT_CHECKED,
            null !== $panel->getTlsPinSha256() => PanelConnectionReport::TLS_PINNED,
            default => PanelConnectionReport::TLS_VERIFIED,
        };
        $reachable = match (true) {
            null === $probe->failure => true,
            $probe->failure->isNetwork(), PanelFailure::NOT_CONFIGURED === $probe->failure => false,
            default => true, // TLS handshake, HTTP status, wrong content: something answered
        };
        $authenticated = match (true) {
            null !== $probe->detected => true,
            PanelFailure::AUTH_REJECTED === $probe->failure => false,
            default => null,
        };

        $inboundCount = null;
        $failure = $probe->failure;
        $message = $probe->message;
        if (SanaeiIntegrationMode::MODERN === $probe->detected) {
            try {
                $inboundCount = count($this->clientsApi->inboundOptions($panel));
            } catch (PanelApiException $e) {
                $failure = $e->failure;
                $message = $e->getMessage();
            }
            // Modern panels work with the token only: no full-admin password is kept.
            $panel->clearPassword()->setUsername(null);
        } elseif (SanaeiIntegrationMode::LEGACY === $probe->detected) {
            $list = $this->legacyApiClient->listInbounds($panel);
            $inboundCount = is_array($list['data']['obj'] ?? null) ? count($list['data']['obj']) : null;
        }

        return new PanelConnectionReport($endpoint->root(), $reachable, $tls, $authenticated, $probe->detected, $probe->latencyMs, $inboundCount, $failure, $message, $now);
    }
}
