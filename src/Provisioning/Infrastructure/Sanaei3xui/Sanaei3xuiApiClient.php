<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Sanaei3xuiApiClient
{
    private array $cookiesByPanel = [];
    private array $loggedInPanels = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
    ) {
    }

    public function login(VpnPanel $panel): array
    {
        $baseUrl = $this->normalizeBaseUrl($panel);
        $username = trim((string) $panel->getUsername());
        $password = (string) $panel->getPassword();

        if ('' === $baseUrl || '' === $username || '' === $password) {
            $this->log(sprintf('panel_login_failed panel_id=%s reason="missing_credentials_or_base_url"', $panel->getId() ?? 'null'));

            return $this->errorResult('invalid_panel_credentials');
        }

        $result = $this->request(
            $panel,
            'POST',
            '/login',
            [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ],
            false
        );

        if ($this->isSuccessfulStatus($result['status'])) {
            $this->loggedInPanels[$this->panelKey($panel)] = true;
            $this->log(sprintf('panel_login_success panel_id=%s base_url="%s"', $panel->getId() ?? 'null', $baseUrl));
        } else {
            $this->loggedInPanels[$this->panelKey($panel)] = false;
            $this->log(sprintf('panel_login_failed panel_id=%s status=%s', $panel->getId() ?? 'null', (string) ($result['status'] ?? null)));
        }

        return $result;
    }

    public function listInbounds(VpnPanel $panel): array
    {
        if (!$this->ensureLogin($panel)) {
            $this->log(sprintf('inbound_list_failure panel_id=%s reason="login_failed"', $panel->getId() ?? 'null'));

            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'GET', '/panel/api/inbounds/list');
    }

    public function getInbound(VpnPanel $panel, string $id): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'GET', '/panel/api/inbounds/get/'.rawurlencode($id));
    }

    public function addClient(VpnPanel $panel, string $inboundId, array $client, array $context = []): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        $this->log(sprintf(
            'add_client_request panel_id=%s inbound_id="%s" %s',
            $panel->getId() ?? 'null',
            $inboundId,
            $this->formatDiagnosticContext($context)
        ));

        $result = $this->request(
            $panel,
            'POST',
            '/panel/api/inbounds/addClient',
            [
                'json' => [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ]
        );

        $this->log(sprintf(
            'add_client_response panel_id=%s inbound_id="%s" status=%s ok=%s success=%s empty=%s error="%s" body_preview="%s"',
            $panel->getId() ?? 'null',
            $inboundId,
            (string) ($result['status'] ?? 'null'),
            (($result['ok'] ?? false) === true) ? 'true' : 'false',
            (($result['success'] ?? false) === true) ? 'true' : 'false',
            (($result['empty'] ?? false) === true) ? 'true' : 'false',
            (string) ($result['error'] ?? ''),
            (string) ($result['bodyPreview'] ?? '')
        ));

        return $result;
    }

    public function updateClient(VpnPanel $panel, string $inboundId, string $clientId, array $client): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request(
            $panel,
            'POST',
            '/panel/api/inbounds/updateClient/'.$clientId,
            [
                'json' => [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ]
        );
    }

    public function deleteClient(VpnPanel $panel, string $inboundId, string $clientId): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'POST', sprintf('/panel/api/inbounds/%s/delClient/%s', rawurlencode($inboundId), rawurlencode($clientId)));
    }

    public function resetClientTraffic(VpnPanel $panel, string $inboundId, string $email): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'POST', sprintf('/panel/api/inbounds/%s/resetClientTraffic/%s', rawurlencode($inboundId), rawurlencode($email)));
    }

    public function getClientTraffic(VpnPanel $panel, string $email): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'GET', '/panel/api/inbounds/getClientTraffics/'.rawurlencode($email));
    }

    private function ensureLogin(VpnPanel $panel): bool
    {
        if (($this->loggedInPanels[$this->panelKey($panel)] ?? false) === true) {
            return true;
        }

        $result = $this->login($panel);

        return $this->isSuccessfulStatus($result['status']);
    }

    private function request(VpnPanel $panel, string $method, string $path, array $options = [], bool $expectJson = true): array
    {
        $baseUrl = $this->normalizeBaseUrl($panel);
        if ('' === $baseUrl) {
            return $this->errorResult('invalid_base_url');
        }

        $options = array_replace($this->panelHttpClientFactory->createRequestOptions($panel), $options);

        $headers = $options['headers'] ?? [];
        $headers[] = 'Accept: application/json';
        $cookieHeader = $this->buildCookieHeader($panel);
        if (null !== $cookieHeader) {
            $headers[] = 'Cookie: '.$cookieHeader;
        }
        $options['headers'] = $headers;

        try {
            $response = $this->httpClient->request($method, $baseUrl.$path, $options);
            $statusCode = $response->getStatusCode();
            $this->captureCookies($panel, $response->getHeaders(false));
            $content = trim($response->getContent(false));

            if ('' === $content) {
                $this->log(sprintf('empty_api_response endpoint="%s" status=%d', $path, $statusCode));

                return [
                    'ok' => $this->isSuccessfulStatus($statusCode),
                    'status' => $statusCode,
                    'data' => null,
                    'empty' => true,
                    'success' => $this->isSuccessfulStatus($statusCode),
                    'bodyPreview' => '',
                    'error' => null,
                ];
            }

            $decoded = json_decode($content, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $businessSuccess = $decoded['success'] ?? null;
                $previewRaw = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return [
                    'ok' => $this->isSuccessfulStatus($statusCode),
                    'status' => $statusCode,
                    'data' => $decoded,
                    'empty' => false,
                    'success' => is_bool($businessSuccess) ? $businessSuccess : $this->isSuccessfulStatus($statusCode),
                    'bodyPreview' => $this->sanitizeSnippet((string) ($previewRaw ?: '')),
                    'error' => null,
                ];
            }

            if ($expectJson) {
                $preview = $this->sanitizeSnippet($content);
                $this->log(sprintf(
                    'non_json_api_response endpoint="%s" status=%d body_preview="%s"',
                    $path,
                    $statusCode,
                    $preview
                ));

                return [
                    'ok' => false,
                    'status' => $statusCode,
                    'data' => null,
                    'empty' => false,
                    'success' => false,
                    'bodyPreview' => $preview,
                    'error' => 'non_json_response',
                ];
            }

            return [
                'ok' => $this->isSuccessfulStatus($statusCode),
                'status' => $statusCode,
                'data' => null,
                'empty' => false,
                'success' => $this->isSuccessfulStatus($statusCode),
                'bodyPreview' => $this->sanitizeSnippet($content),
                'error' => null,
            ];
        } catch (TransportExceptionInterface $e) {
            $this->log(sprintf('api_transport_failure endpoint="%s" message="%s"', $path, $e->getMessage()));

            return $this->errorResult('transport_failure');
        }
    }

    private function captureCookies(VpnPanel $panel, array $headers): void
    {
        $cookies = $this->cookiesByPanel[$this->panelKey($panel)] ?? [];

        foreach ($headers['set-cookie'] ?? [] as $rawCookie) {
            $cookieParts = explode(';', (string) $rawCookie, 2);
            $nameValue = trim($cookieParts[0] ?? '');
            if ('' === $nameValue || !str_contains($nameValue, '=')) {
                continue;
            }

            $nameValueParts = explode('=', $nameValue, 2);
            if (2 !== count($nameValueParts)) {
                continue;
            }

            [$cookieName, $cookieValue] = $nameValueParts;
            $cookieName = trim($cookieName);
            if ('' === $cookieName) {
                continue;
            }

            $cookies[$cookieName] = trim($cookieValue);
        }

        if ([] !== $cookies) {
            $this->cookiesByPanel[$this->panelKey($panel)] = $cookies;
        }
    }

    private function buildCookieHeader(VpnPanel $panel): ?string
    {
        $cookies = $this->cookiesByPanel[$this->panelKey($panel)] ?? [];
        if ([] === $cookies) {
            return null;
        }

        $pairs = [];
        foreach ($cookies as $cookieName => $cookieValue) {
            $pairs[] = sprintf('%s=%s', $cookieName, $cookieValue);
        }

        return implode('; ', $pairs);
    }

    private function normalizeBaseUrl(VpnPanel $panel): string
    {
        return rtrim((string) $panel->getBaseUrl(), '/');
    }

    private function panelKey(VpnPanel $panel): string
    {
        return sprintf('%s|%s|%s', $panel->getId() ?? 0, $panel->getBaseUrl() ?? '', $panel->getUsername() ?? '');
    }

    private function isSuccessfulStatus(?int $statusCode): bool
    {
        return null !== $statusCode && $statusCode >= 200 && $statusCode < 300;
    }

    private function sanitizeSnippet(string $text): string
    {
        $snippet = preg_replace('/https?:\/\/\S+/i', '[url-redacted]', $text) ?? $text;
        $snippet = preg_replace('/("?(?:password|passwd|token|cookie|session)"?\s*[:=]\s*)"[^"]*"/i', '$1"[redacted]"', $snippet) ?? $snippet;
        $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;

        return mb_substr(trim($snippet), 0, 300);
    }

    private function errorResult(string $error): array
    {
        return [
            'ok' => false,
            'status' => null,
            'data' => null,
            'empty' => false,
            'success' => false,
            'bodyPreview' => '',
            'error' => $error,
        ];
    }

    private function formatDiagnosticContext(array $context): string
    {
        $allowed = [
            'localInboundId',
            'remoteInboundId',
            'protocol',
            'network',
            'security',
            'clientUuid',
            'email',
            'totalGB',
            'expiryTime',
        ];

        $pairs = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }

            $pairs[] = sprintf('%s="%s"', $key, $this->sanitizeSnippet((string) $context[$key]));
        }

        return implode(' ', $pairs);
    }

    private function log(string $message): void
    {
        error_log('[Sanaei3xuiApiClient] '.$message);
    }
}
