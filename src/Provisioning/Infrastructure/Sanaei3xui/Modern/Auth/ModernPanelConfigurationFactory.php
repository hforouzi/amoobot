<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\RetryPolicy;

final class ModernPanelConfigurationFactory
{
    public function __construct(
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
        private readonly RetryPolicy $retryPolicy,
    ) {
    }

    public function resolve(VpnPanel $panel): ModernPanelConfigurationResult
    {
        if ('sanaei_3xui' !== $panel->getType()) {
            return ModernPanelConfigurationResult::invalid(
                ModernPanelConfigurationFailure::INVALID_PANEL_TYPE,
                'Panel type must be sanaei_3xui.'
            );
        }

        if (SanaeiIntegrationMode::MODERN !== $panel->getSanaeiIntegrationMode()) {
            return ModernPanelConfigurationResult::invalid(
                ModernPanelConfigurationFailure::INVALID_INTEGRATION_MODE,
                'Panel must use the Modern Sanaei integration mode.'
            );
        }

        $baseUrl = rtrim(trim((string) ($panel->getBaseUrl() ?? '')), '/');
        if ('' === $baseUrl) {
            return ModernPanelConfigurationResult::invalid(
                ModernPanelConfigurationFailure::MISSING_BASE_URL,
                'Modern Sanaei panel base URL is required.'
            );
        }

        if (false === filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return ModernPanelConfigurationResult::invalid(
                ModernPanelConfigurationFailure::INVALID_BASE_URL,
                'Modern Sanaei panel base URL must be a valid URL.'
            );
        }

        $apiToken = trim((string) ($panel->getApiToken() ?? ''));
        if ('' === $apiToken) {
            return ModernPanelConfigurationResult::invalid(
                ModernPanelConfigurationFailure::MISSING_API_TOKEN,
                'Modern Sanaei API token is required.'
            );
        }

        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $diagnostics = $this->panelHttpClientFactory->diagnostics($panel);

        return ModernPanelConfigurationResult::valid(new ModernPanelConfiguration(
            baseUrl: $baseUrl,
            apiToken: $apiToken,
            timeout: (int) ($diagnostics['timeout'] ?? 15),
            tlsVerification: $this->resolveTlsVerify($config),
            proxyConfiguration: [
                'enabled' => true === ($diagnostics['proxyEnabled'] ?? false),
                'source' => (string) ($diagnostics['proxySource'] ?? 'none'),
                'type' => (string) ($diagnostics['proxyType'] ?? 'none'),
                'host' => (string) ($diagnostics['proxyHost'] ?? ''),
                'port' => (string) ($diagnostics['proxyPort'] ?? ''),
            ],
            retryCount: max(0, $this->retryPolicy->maxAttempts($panel) - 1),
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveTlsVerify(array $config): bool
    {
        $value = $config['tls_verify'] ?? ($config['verify_peer'] ?? true);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return 1 === $value;
        }
        if (!is_scalar($value)) {
            return true;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }
}
