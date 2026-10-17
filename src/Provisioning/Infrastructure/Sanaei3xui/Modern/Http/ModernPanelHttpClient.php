<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bearer-token calls to the 3x-ui REST API (paths relative to the panel's base URL, e.g. "panel/api/clients/list").
 *
 * Every answer is the envelope {success, msg, obj}; success:true returns a PanelResponse, everything else throws a
 * PanelApiException naming the failure (PanelFailure). Nothing that leaves this class carries the token.
 */
class ModernPanelHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, scalar> $query
     */
    public function get(VpnPanel $panel, string $path, array $query = []): PanelResponse
    {
        return $this->request($panel, 'GET', $path, $query, null);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null     $json
     */
    public function post(VpnPanel $panel, string $path, ?array $json = null, array $query = []): PanelResponse
    {
        return $this->request($panel, 'POST', $path, $query, $json);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null     $json
     */
    private function request(VpnPanel $panel, string $method, string $path, array $query, ?array $json): PanelResponse
    {
        $token = $this->token($panel);
        $endpoint = PanelEndpoint::fromPanel($panel);
        $url = $endpoint->url($path, $query);
        $options = $this->panelHttpClientFactory->createRequestOptions($panel);
        $options['headers'] = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token];
        if (null !== $json) {
            $options['json'] = $json;
        }

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (HttpClientException $e) {
            $failure = self::classifyTransportError($e->getMessage());
            $this->logFailure($panel, $method, $path, $failure, null, $startedAt);

            throw new PanelApiException($failure, sprintf('%s %s: %s', $method, $path, SecretRedactor::redact($e->getMessage(), [$token], 300)));
        }

        $latencyMs = self::elapsedMs($startedAt);
        try {
            $result = $this->parse($method, $path, $status, $headers, $body, $latencyMs, $token);
        } catch (PanelApiException $e) {
            $this->logFailure($panel, $method, $path, $e->failure, $status, $startedAt);

            throw $e;
        }
        $this->logger->debug('sanaei_modern_request', ['panel_id' => $panel->getId(), 'method' => $method, 'path' => $path, 'status' => $status, 'latency_ms' => $latencyMs]);

        return $result;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function parse(string $method, string $path, int $status, array $headers, string $body, int $latencyMs, string $token): PanelResponse
    {
        $what = $method.' '.$path;
        if (401 === $status || 403 === $status) {
            throw new PanelApiException(PanelFailure::AUTH_REJECTED, sprintf('%s: HTTP %d, the panel rejected the API token.', $what, $status), $status);
        }
        if (404 === $status) {
            throw new PanelApiException(PanelFailure::WRONG_URL, sprintf('%s: HTTP 404, no API at this URL (check the URL and the web base path).', $what), $status);
        }
        if ($status >= 300 && $status < 400) {
            $location = SecretRedactor::redact((string) ($headers['location'][0] ?? ''), [$token], 200);

            throw new PanelApiException(PanelFailure::HTTP_ERROR, sprintf('%s: HTTP %d redirect to "%s" (redirects are not followed; use the final URL).', $what, $status, $location), $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new PanelApiException(PanelFailure::HTTP_ERROR, sprintf('%s: HTTP %d.', $what, $status), $status);
        }

        $trimmed = ltrim($body);
        $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));
        if (str_contains($contentType, 'text/html') || str_starts_with($trimmed, '<')) {
            throw new PanelApiException(PanelFailure::WRONG_URL, sprintf('%s: the panel answered with an HTML page instead of JSON (wrong URL or web base path, or a login page).', $what), $status);
        }
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('%s: the answer is not JSON.', $what), $status);
        }
        if (!is_array($decoded) || !is_bool($decoded['success'] ?? null)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('%s: the answer is not the {success, msg, obj} envelope.', $what), $status);
        }
        $msg = is_scalar($decoded['msg'] ?? null) ? SecretRedactor::redact((string) $decoded['msg'], [$token], 300) : null;
        if (false === $decoded['success']) {
            throw new PanelApiException(PanelFailure::PANEL_REJECTED, sprintf('%s: the panel refused: %s', $what, '' !== (string) $msg ? $msg : '(no message)'), $status, $msg);
        }

        return new PanelResponse($decoded['obj'] ?? null, $msg, $status, $latencyMs);
    }

    private function token(VpnPanel $panel): string
    {
        $token = trim((string) $panel->getApiToken());
        if ('' === $token) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, 'No API token is configured for this panel.');
        }

        return $token;
    }

    /**
     * curl/stream error texts -> failure. Checked in this order: certificate problems also mention the host.
     */
    public static function classifyTransportError(string $message): PanelFailure
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'ssl') || str_contains($m, 'certificate') || str_contains($m, 'tls') || str_contains($m, 'fingerprint') => PanelFailure::TLS_CERTIFICATE,
            str_contains($m, 'resolve host') || str_contains($m, 'getaddrinfo') || str_contains($m, 'name or service not known') || str_contains($m, 'nodename nor servname') || str_contains($m, 'no address associated') => PanelFailure::NETWORK_DNS,
            str_contains($m, 'timed out') || str_contains($m, 'timeout') => PanelFailure::NETWORK_TIMEOUT,
            str_contains($m, 'refused') || str_contains($m, "couldn't connect") || str_contains($m, 'failed to connect') || str_contains($m, 'no route to host') => PanelFailure::NETWORK_REFUSED,
            default => PanelFailure::NETWORK_OTHER,
        };
    }

    private function logFailure(VpnPanel $panel, string $method, string $path, PanelFailure $failure, ?int $status, float $startedAt): void
    {
        $this->logger->warning('sanaei_modern_request_failed', [
            'panel_id' => $panel->getId(),
            'method' => $method,
            'path' => $path,
            'failure' => $failure->value,
            'status' => $status,
            'latency_ms' => self::elapsedMs($startedAt),
        ]);
    }

    private static function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
