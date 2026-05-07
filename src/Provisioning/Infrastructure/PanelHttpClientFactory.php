<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure;

use App\Entity\VpnPanel;

final class PanelHttpClientFactory
{
    public function __construct(
        private readonly string $panelProxyEnabled = 'false',
        private readonly string $panelProxyType = 'socks5',
        private readonly string $panelProxyHost = '',
        private readonly string $panelProxyPort = '',
        private readonly string $panelProxyUsername = '',
        private readonly string $panelProxyPassword = '',
        private readonly string $panelProxyTimeout = '',
    ) {
    }

    public function createRequestOptions(VpnPanel $panel): array
    {
        $transport = $this->resolveTransport($panel);
        $options = ['timeout' => $transport['timeout']];

        if (true === $transport['proxyEnabled'] && null !== $transport['proxyUrl']) {
            $options['proxy'] = $transport['proxyUrl'];
        }

        return $options;
    }

    public function diagnostics(VpnPanel $panel): array
    {
        $transport = $this->resolveTransport($panel);

        return [
            'panelId' => $panel->getId(),
            'proxySource' => $transport['proxySource'],
            'proxyEnabled' => $transport['proxyEnabled'],
            'proxyType' => $transport['proxyType'],
            'proxyHost' => $transport['proxyHost'],
            'proxyPort' => $transport['proxyPort'],
            'timeout' => $transport['timeout'],
        ];
    }

    private function resolveTransport(VpnPanel $panel): array
    {
        $panelConfig = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $panelProxy = is_array($panelConfig['proxy'] ?? null) ? $panelConfig['proxy'] : [];

        $panelProxyEnabled = $this->toBool($panelProxy['enabled'] ?? false);
        if ($panelProxyEnabled) {
            $proxyType = $this->normalizeProxyType((string) ($panelProxy['type'] ?? ''));
            $proxyHost = trim((string) ($panelProxy['host'] ?? ''));
            $proxyPort = trim((string) ($panelProxy['port'] ?? ''));
            $proxyUsername = trim((string) ($panelProxy['username'] ?? ''));
            $proxyPassword = trim((string) ($panelProxy['password'] ?? ''));
            $proxyUrl = $this->buildProxyUrl($proxyType, $proxyHost, $proxyPort, $proxyUsername, $proxyPassword);

            return [
                'proxySource' => 'panel_config',
                'proxyEnabled' => null !== $proxyUrl,
                'proxyType' => $proxyType,
                'proxyHost' => $proxyHost,
                'proxyPort' => $proxyPort,
                'timeout' => $this->resolveTimeout($panelConfig),
                'proxyUrl' => $proxyUrl,
            ];
        }

        $envEnabled = $this->toBool($this->panelProxyEnabled);
        if ($envEnabled) {
            $proxyType = $this->normalizeProxyType($this->panelProxyType);
            $proxyHost = trim($this->panelProxyHost);
            $proxyPort = trim($this->panelProxyPort);
            $proxyUsername = trim($this->panelProxyUsername);
            $proxyPassword = trim($this->panelProxyPassword);
            $proxyUrl = $this->buildProxyUrl($proxyType, $proxyHost, $proxyPort, $proxyUsername, $proxyPassword);

            return [
                'proxySource' => 'env',
                'proxyEnabled' => null !== $proxyUrl,
                'proxyType' => $proxyType,
                'proxyHost' => $proxyHost,
                'proxyPort' => $proxyPort,
                'timeout' => $this->resolveTimeout($panelConfig),
                'proxyUrl' => $proxyUrl,
            ];
        }

        return [
            'proxySource' => 'none',
            'proxyEnabled' => false,
            'proxyType' => 'none',
            'proxyHost' => '',
            'proxyPort' => '',
            'timeout' => $this->resolveTimeout($panelConfig),
            'proxyUrl' => null,
        ];
    }

    private function resolveTimeout(array $panelConfig): int
    {
        $panelTimeout = $this->toPositiveInt($panelConfig['timeout'] ?? null);
        if (null !== $panelTimeout) {
            return $panelTimeout;
        }

        $envTimeout = $this->toPositiveInt($this->panelProxyTimeout);
        if (null !== $envTimeout) {
            return $envTimeout;
        }

        return 15;
    }

    private function buildProxyUrl(string $type, string $host, string $port, string $username, string $password): ?string
    {
        if ('' === $host || '' === $port || !ctype_digit($port)) {
            return null;
        }

        $scheme = 'socks5' === $type ? 'socks5' : 'http';
        if ('' !== $username || '' !== $password) {
            $credentials = rawurlencode($username).':'.rawurlencode($password).'@';

            return sprintf('%s://%s%s:%s', $scheme, $credentials, $host, $port);
        }

        return sprintf('%s://%s:%s', $scheme, $host, $port);
    }

    private function normalizeProxyType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return 'http' === $normalized ? 'http' : 'socks5';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 1 === $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);
        if ('' === $stringValue || !ctype_digit($stringValue)) {
            return null;
        }

        $intValue = (int) $stringValue;

        return $intValue > 0 ? $intValue : null;
    }
}
