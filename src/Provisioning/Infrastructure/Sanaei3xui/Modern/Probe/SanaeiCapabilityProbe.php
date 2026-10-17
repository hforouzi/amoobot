<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiApiClient;

/**
 * Decides which API a panel speaks, from positive answers only (never from a version string or other signals):
 *
 * 1. With a token: GET panel/api/clients/list/paged?page=1&pageSize=1. success:true with an object/list => MODERN.
 * 2. Otherwise, unless the panel was unreachable (DNS, refused, timeout, TLS: the legacy login would fail the same
 *    way), the legacy cookie login with the stored username/password: login + inbound list OK => LEGACY.
 * 3. Neither => no decision; the report names the token probe's failure.
 */
class SanaeiCapabilityProbe
{
    public const PROBE_PATH = 'panel/api/clients/list/paged';

    public function __construct(
        private readonly ModernPanelHttpClient $httpClient,
        private readonly Sanaei3xuiApiClient $legacyApiClient,
    ) {
    }

    public function probe(VpnPanel $panel): CapabilityProbeReport
    {
        $tokenFailure = null;
        if ('' !== trim((string) $panel->getApiToken())) {
            try {
                $response = $this->httpClient->get($panel, self::PROBE_PATH, ['page' => 1, 'pageSize' => 1]);
                if (is_array($response->obj)) {
                    return new CapabilityProbeReport(SanaeiIntegrationMode::MODERN, null, 'The API token works with the clients API (modern 3x-ui).', $response->latencyMs, true, false, $response->httpStatus);
                }
                $tokenFailure = new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET '.self::PROBE_PATH.': success:true but obj is not a list/object.', $response->httpStatus);
            } catch (PanelApiException $e) {
                $tokenFailure = $e;
            }
            if ($tokenFailure->failure->isUnreachable() || PanelFailure::NOT_CONFIGURED === $tokenFailure->failure) {
                return new CapabilityProbeReport(null, $tokenFailure->failure, $tokenFailure->getMessage(), null, true, false, $tokenFailure->httpStatus);
            }
        }

        $legacy = $this->probeLegacy($panel);
        if (true === $legacy) {
            return new CapabilityProbeReport(SanaeiIntegrationMode::LEGACY, null, null === $tokenFailure
                ? 'The username/password login works (legacy 3x-ui API).'
                : 'The username/password login works (legacy 3x-ui API); the token probe failed: '.$tokenFailure->getMessage(), null, null !== $tokenFailure, true);
        }

        if (null !== $tokenFailure) {
            return new CapabilityProbeReport(null, $tokenFailure->failure, $tokenFailure->getMessage().(null === $legacy ? '' : ' Legacy login: '.$legacy), null, true, null !== $legacy, $tokenFailure->httpStatus);
        }

        return new CapabilityProbeReport(null, PanelFailure::NOT_CONFIGURED, null === $legacy ? 'No API token is configured (and no username/password for an old panel).' : 'No API token is configured; legacy login: '.$legacy, null, false, null !== $legacy);
    }

    /**
     * Probes and stores the result on the panel (caller flushes).
     */
    public function probeAndRecord(VpnPanel $panel): CapabilityProbeReport
    {
        $report = $this->probe($panel);
        $panel->recordProbe($report->detected, $report->status(), new \DateTimeImmutable());

        return $report;
    }

    /**
     * @return true|string|null true = legacy API confirmed, string = why not (safe), null = not attempted (no credentials)
     */
    private function probeLegacy(VpnPanel $panel): true|string|null
    {
        if ('' === trim((string) $panel->getUsername()) || '' === (string) $panel->getPassword()) {
            return null;
        }
        // A copy forced to the legacy cookie flow: the stored panel keeps its settings.
        $legacyPanel = (clone $panel)->setApiVersion('legacy')->setAuthMode('cookie')->setClientApiMode('inbound_legacy');
        try {
            $login = $this->legacyApiClient->login($legacyPanel);
            if (true !== ($login['ok'] ?? false) || false === ($login['data']['success'] ?? null)) {
                return 'login failed';
            }
            $list = $this->legacyApiClient->listInbounds($legacyPanel);
        } catch (\Throwable) {
            return 'login request failed';
        }

        return true === ($list['ok'] ?? false) && true === ($list['data']['success'] ?? false) ? true : 'inbound list failed';
    }
}
