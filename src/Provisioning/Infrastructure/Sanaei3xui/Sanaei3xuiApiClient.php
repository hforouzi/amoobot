<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui;

use App\Entity\VpnPanel;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Sanaei3xuiApiClient
{
    private array $cookiesByPanel = [];
    private array $loggedInPanels = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
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

    public function getInbound(VpnPanel $panel, int $id): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'GET', '/panel/api/inbounds/get/'.$id);
    }

    public function addClient(VpnPanel $panel, int $inboundId, array $client): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request(
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
    }

    public function updateClient(VpnPanel $panel, int $inboundId, string $clientId, array $client): array
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

    public function deleteClient(VpnPanel $panel, int $inboundId, string $clientId): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'POST', sprintf('/panel/api/inbounds/%d/delClient/%s', $inboundId, rawurlencode($clientId)));
    }

    public function resetClientTraffic(VpnPanel $panel, int $inboundId, string $email): array
    {
        if (!$this->ensureLogin($panel)) {
            return $this->errorResult('login_failed');
        }

        return $this->request($panel, 'POST', sprintf('/panel/api/inbounds/%d/resetClientTraffic/%s', $inboundId, rawurlencode($email)));
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
                    'error' => null,
                ];
            }

            $decoded = json_decode($content, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return [
                    'ok' => $this->isSuccessfulStatus($statusCode),
                    'status' => $statusCode,
                    'data' => $decoded,
                    'empty' => false,
                    'error' => null,
                ];
            }

            if ($expectJson) {
                $this->log(sprintf(
                    'non_json_api_response endpoint="%s" status=%d body_preview="%s"',
                    $path,
                    $statusCode,
                    $this->sanitizeSnippet($content)
                ));

                return [
                    'ok' => false,
                    'status' => $statusCode,
                    'data' => null,
                    'empty' => false,
                    'error' => 'non_json_response',
                ];
            }

            return [
                'ok' => $this->isSuccessfulStatus($statusCode),
                'status' => $statusCode,
                'data' => null,
                'empty' => false,
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

            [$cookieName, $cookieValue] = explode('=', $nameValue, 2);
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
            'error' => $error,
        ];
    }

    private function log(string $message): void
    {
        error_log('[Sanaei3xuiApiClient] '.$message);
    }
}
