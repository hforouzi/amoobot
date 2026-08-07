<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthenticationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidJsonException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;

final class SanaeiModernHttpRequestBuilder
{
    private const METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

    public function __construct(
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function build(VpnPanel $panel, string $method, string $path, mixed $body = null, array $headers = []): ModernHttpRequest
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidResponseException(sprintf('Unsupported Sanaei Modern HTTP method "%s".', $method));
        }

        $requestId = $this->generateRequestId();
        $token = trim((string) ($panel->getApiToken() ?? ''));
        if ('' === $token) {
            throw new AuthenticationFailedException('Sanaei Modern API token is not configured.', null, $requestId);
        }

        $requestHeaders = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'X-Request-ID' => $requestId,
        ], $headers);

        $transportOptions = $this->panelHttpClientFactory->createRequestOptions($panel);
        $transportOptions['verify_peer'] = $this->resolveTlsVerify($panel);
        $timeout = $this->resolveTimeout($panel);
        if (null !== $timeout) {
            $transportOptions['timeout'] = $timeout;
        }

        return new ModernHttpRequest(
            method: $method,
            url: $this->buildUrl($panel, $path),
            path: $this->normalizePath($path),
            headers: $requestHeaders,
            encodedBody: null === $body ? null : $this->encodeJson($body, $requestId),
            requestId: $requestId,
            transportOptions: $transportOptions
        );
    }

    public function buildUrl(VpnPanel $panel, string $path): string
    {
        $baseUrl = rtrim(trim((string) ($panel->getBaseUrl() ?? '')), '/');
        if ('' === $baseUrl) {
            throw new InvalidResponseException('Sanaei Modern panel base URL is not configured.');
        }

        $basePath = $this->normalizeBasePath((string) ($panel->getBasePath() ?? ''));
        $normalizedPath = $basePath.$this->normalizePath($path);
        if ('' === $basePath && str_ends_with($baseUrl, '/panel') && str_starts_with($normalizedPath, '/panel/api')) {
            $normalizedPath = substr($normalizedPath, strlen('/panel'));
        }

        return $baseUrl.$normalizedPath;
    }

    private function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);
        if ('' === $basePath || '/' === $basePath) {
            return '';
        }

        $basePath = '/'.trim($basePath, '/');

        return preg_replace('#/+#', '/', $basePath) ?? $basePath;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        $path = preg_replace('#(?<!:)/+#', '/', $path) ?? $path;

        if ('/panel/api' === $path || str_starts_with($path, '/panel/api/')) {
            return $path;
        }

        if ('/api' === $path || str_starts_with($path, '/api/')) {
            return '/panel'.$path;
        }

        return '/panel/api'.$path;
    }

    private function encodeJson(mixed $body, string $requestId): string
    {
        if (is_object($body) && !$body instanceof \JsonSerializable) {
            $body = get_object_vars($body);
        }

        try {
            return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidJsonException('Sanaei Modern request payload could not be encoded as JSON.', null, $requestId, $exception);
        }
    }

    private function resolveTlsVerify(VpnPanel $panel): bool
    {
        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $value = $config['tls_verify'] ?? ($config['verify_peer'] ?? true);

        return $this->toBool($value, true);
    }

    private function resolveTimeout(VpnPanel $panel): ?int
    {
        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $value = $config['modern_timeout'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ('' === $normalized || !ctype_digit($normalized)) {
            return null;
        }

        $timeout = (int) $normalized;

        return $timeout > 0 ? $timeout : null;
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return 1 === $value;
        }
        if (!is_scalar($value)) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
