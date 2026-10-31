<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelCredentials;
use App\Provisioning\Infrastructure\PanelHttpClientFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Bearer-token calls to the 3x-ui REST API (paths relative to the panel's base URL, e.g. "panel/api/clients/list").
 *
 * Every answer is the envelope {success, msg, obj}; success:true returns a PanelResponse, everything else throws a
 * PanelApiException naming the failure (PanelFailure). Nothing that leaves this class carries the token.
 *
 * Transport rules (provisioning calls run while database row locks are held, so they must fail fast):
 * - connect/idle timeout 3 s, at most 10 s per request; redirects are never followed;
 * - retries with backoff only for GET, only for transient failures (refused, timeout, 502/503/504), at most
 *   MAX_ATTEMPTS and within RETRY_BUDGET_MS; a POST is sent exactly once (a retry could apply it twice);
 * - HTTPS certificates are verified; a self-signed panel is trusted only through its pinned public key;
 * - plain http:// only to loopback or private addresses (the bearer token would otherwise travel in clear text);
 * - the per-panel proxy setting (PANEL_PROXY_*) is honoured;
 * - answers are requested uncompressed (Accept-Encoding: identity);
 * - a GET may cap the answer's size (maxBodyBytes: counted on the decoded body as it arrives, the transfer is aborted
 *   beyond it and not retried) and name itself with a label for
 *   logs and errors, so a path that carries a credential (a subscription id) is never written out.
 * - inside a PanelCallBudget pass (the attach pass after a sale) each request ends by the pass's end at the latest, and
 *   none is sent once it is over;
 * Each finished call is recorded on the panel (consecutive failures -> degraded); the caller flushes.
 */
class ModernPanelHttpClient
{
    public const CONNECT_TIMEOUT_S = 3;
    public const MAX_DURATION_S = 10;
    public const MAX_ATTEMPTS = 3;
    public const RETRY_BUDGET_MS = 12000;
    private const RETRY_STATUSES = [502, 503, 504];

    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /**
     * @param (\Closure(int): void)|null $sleeper milliseconds to wait before a retry (tests pass a recorder)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PanelHttpClientFactory $panelHttpClientFactory,
        private readonly PanelCredentials $credentials,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?\Closure $sleeper = null,
        private readonly PanelCallBudget $budget = new PanelCallBudget(),
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void { usleep($ms * 1000); };
    }

    /**
     * @param array<string, scalar> $query
     * @param int|null              $maxBodyBytes the largest answer accepted (UNEXPECTED_RESPONSE beyond it, not retried)
     * @param string|null           $label        the path as logs and error messages show it (default: the path)
     * @param bool                  $recordHealth false: a failure is not counted on the panel (bulk link refreshes:
     *                                            one bad run must not mark the panel degraded and stop its sales)
     */
    public function get(VpnPanel $panel, string $path, array $query = [], ?int $maxBodyBytes = null, ?string $label = null, bool $recordHealth = true): PanelResponse
    {
        return $this->request($panel, 'GET', $path, $query, null, $maxBodyBytes, $label, $recordHealth);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null     $json
     * @param bool                  $recordHealth false: a failure is not counted on the panel (the scheduled retry of
     *                                            additional inbounds, M2b: a bulk run, as for the GETs)
     */
    public function post(VpnPanel $panel, string $path, ?array $json = null, array $query = [], bool $recordHealth = true): PanelResponse
    {
        return $this->request($panel, 'POST', $path, $query, $json, null, null, $recordHealth);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null     $json
     */
    private function request(VpnPanel $panel, string $method, string $path, array $query, ?array $json, ?int $maxBodyBytes = null, ?string $label = null, bool $recordHealth = true): PanelResponse
    {
        $token = $this->token($panel);
        $endpoint = PanelEndpoint::fromPanel($panel);
        $endpoint->assertTransportAllowed();
        $url = $endpoint->url($path, $query);
        $options = $this->options($panel, $endpoint, $token, $json);
        $secrets = self::secretsOf($endpoint, $path, $label, $token);
        $path = $label ?? $path;
        if (null !== $maxBodyBytes) {
            $options['on_progress'] = static function (int $downloaded, int $size) use ($maxBodyBytes): void {
                if ($downloaded > $maxBodyBytes || $size > $maxBodyBytes) {
                    throw new AnswerTooLarge();
                }
            };
        }

        $firstStartedAt = microtime(true);
        for ($attempt = 1; ; ++$attempt) {
            // Inside a time-limited pass (PanelCallBudget, M2d B1): never past its end, whatever this call's own limit.
            // Under PanelCallBudget::MIN_SECONDS_TO_SEND left nothing is sent (M2e E2), and the limits given to curl are
            // never below 1 ms: curl counts max_duration in whole milliseconds and 0 means unlimited.
            $left = $this->budget->secondsLeft();
            if (null !== $left) {
                if (!$this->budget->canSend()) {
                    throw new PanelApiException(PanelFailure::NETWORK_TIMEOUT, sprintf('%s not sent: the time limit of this pass is used up', $method));
                }
                $options['max_duration'] = max(PanelCallBudget::MIN_REQUEST_SECONDS, min(self::MAX_DURATION_S, $left));
                $options['timeout'] = max(PanelCallBudget::MIN_REQUEST_SECONDS, min(self::CONNECT_TIMEOUT_S, $left));
            }
            try {
                $response = $this->attempt($panel, $method, $path, $url, $options, $secrets, $maxBodyBytes);
                $panel->recordCallSuccess();

                return $response;
            } catch (PanelApiException $e) {
                $backoffMs = 250 * (2 ** ($attempt - 1));
                $retry = 'GET' === $method
                    && $attempt < self::MAX_ATTEMPTS
                    && self::isTransient($e)
                    && self::elapsedMs($firstStartedAt) + $backoffMs < self::RETRY_BUDGET_MS
                    && (null === ($left = $this->budget->secondsLeft()) || 1000 * $left - $backoffMs >= 1000 * PanelCallBudget::MIN_SECONDS_TO_SEND);
                if ($retry) {
                    ($this->sleeper)($backoffMs);

                    continue;
                }
                if ($recordHealth) {
                    $this->recordFailure($panel, $e);
                }

                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $secrets what no message may contain (token, base path, the path's hidden segments)
     */
    private function attempt(VpnPanel $panel, string $method, string $path, string $url, #[\SensitiveParameter] array $options, #[\SensitiveParameter] array $secrets, ?int $maxBodyBytes = null): PanelResponse
    {
        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            // Headers first, without throwing: a 4xx/5xx is classified from its body below, not by the transport.
            $headers = $response->getHeaders(false);
            $body = null === $maxBodyBytes ? $response->getContent(false) : $this->cappedBody($response, $maxBodyBytes);
        } catch (AnswerTooLarge) {
            $this->logFailure($panel, $method, $path, PanelFailure::UNEXPECTED_RESPONSE, null, $startedAt);

            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('%s %s: the answer is larger than %d bytes.', $method, $path, (int) $maxBodyBytes));
        } catch (HttpClientException $e) {
            if ($e->getPrevious() instanceof AnswerTooLarge) {
                $this->logFailure($panel, $method, $path, PanelFailure::UNEXPECTED_RESPONSE, null, $startedAt);

                throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('%s %s: the answer is larger than %d bytes.', $method, $path, (int) $maxBodyBytes));
            }
            $failure = self::classifyTransportError($e->getMessage());
            $this->logFailure($panel, $method, $path, $failure, null, $startedAt);
            if (PanelFailure::TLS_CERTIFICATE === $failure && null !== $panel->getTlsPinSha256()) {
                throw new PanelApiException($failure, sprintf('%s %s: the panel certificate does not match the pinned public key (was the certificate replaced?).', $method, $path));
            }

            // Transport messages quote the URL, as the transport normalised it (default port dropped, ...): every URL is
            // replaced by the label, then the base path and the hidden path segments are removed wherever they remain.
            throw new PanelApiException($failure, sprintf('%s %s: %s', $method, $path, self::clean($e->getMessage(), $secrets, $path, 300)));
        }

        $latencyMs = self::elapsedMs($startedAt);
        try {
            $result = $this->parse($method, $path, $status, $headers, $body, $latencyMs, $secrets);
        } catch (PanelApiException $e) {
            $this->logFailure($panel, $method, $path, $e->failure, $status, $startedAt);

            throw $e;
        }
        $this->logger->debug('sanaei_modern_request', ['panel_id' => $panel->getId(), 'method' => $method, 'path' => SecretRedactor::maskAccess($path), 'status' => $status, 'latency_ms' => $latencyMs]);

        return $result;
    }

    /**
     * The body, read chunk by chunk and counted after decoding (a compressed answer is counted as it unpacks), so an
     * answer beyond the cap is aborted without ever being held whole in memory.
     *
     * @throws AnswerTooLarge
     */
    private function cappedBody(ResponseInterface $response, int $maxBodyBytes): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $maxBodyBytes) {
                $response->cancel();

                throw new AnswerTooLarge();
            }
        }

        return $body;
    }

    /**
     * @param array<string, list<string>> $headers
     * @param list<string>                $secrets
     */
    private function parse(string $method, string $path, int $status, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, int $latencyMs, #[\SensitiveParameter] array $secrets): PanelResponse
    {
        $what = $method.' '.$path;
        if (401 === $status || 403 === $status) {
            throw new PanelApiException(PanelFailure::AUTH_REJECTED, sprintf('%s: HTTP %d, the panel rejected the API token.', $what, $status), $status);
        }
        if (404 === $status) {
            // A JSON envelope with 404 is the API answering "no such record"; the bare 404 page means no API here.
            $decoded = json_decode(ltrim($body), true);
            if (is_array($decoded) && is_bool($decoded['success'] ?? null)) {
                $msg = is_scalar($decoded['msg'] ?? null) ? self::clean((string) $decoded['msg'], $secrets, $path, 300) : null;

                throw new PanelApiException(PanelFailure::PANEL_REJECTED, sprintf('%s: HTTP 404: %s', $what, '' !== (string) $msg ? $msg : 'not found'), $status, $msg);
            }

            throw new PanelApiException(PanelFailure::WRONG_URL, sprintf('%s: HTTP 404, no API at this URL (check the URL and the web base path).', $what), $status);
        }
        if ($status >= 300 && $status < 400) {
            $location = self::clean((string) ($headers['location'][0] ?? ''), $secrets, $path, 200);

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
        $msg = is_scalar($decoded['msg'] ?? null) ? self::clean((string) $decoded['msg'], $secrets, $path, 300) : null;
        if (false === $decoded['success']) {
            throw new PanelApiException(PanelFailure::PANEL_REJECTED, sprintf('%s: the panel refused: %s', $what, '' !== (string) $msg ? $msg : '(no message)'), $status, $msg);
        }

        return new PanelResponse($decoded['obj'] ?? null, $msg, $status, $latencyMs);
    }

    /**
     * @param array<mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function options(VpnPanel $panel, PanelEndpoint $endpoint, #[\SensitiveParameter] string $token, ?array $json): array
    {
        try {
            $options = $this->panelHttpClientFactory->createRequestOptions($panel);
        } catch (\RuntimeException $e) {
            throw new PanelApiException(PanelFailure::NOT_CONFIGURED, SecretRedactor::redact($e->getMessage()));
        }
        $options['timeout'] = self::CONNECT_TIMEOUT_S;
        $options['max_duration'] = self::MAX_DURATION_S;
        $options['max_redirects'] = 0;
        // identity: no compressed answer, so a size cap counts what is really received (a small gzip can unpack to GBs).
        $options['headers'] = ['Accept' => 'application/json', 'Accept-Encoding' => 'identity', 'Authorization' => 'Bearer '.$token];
        if (null !== $json) {
            $options['json'] = $json;
        }

        $pin = $panel->getTlsPinSha256();
        if (null !== $pin && $endpoint->isHttps()) {
            // Self-signed panel: no CA chain to verify, the pinned public key is the trust anchor (curl checks it
            // on every connection, and refuses any other certificate).
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
            $options['peer_fingerprint'] = ['pin-sha256' => [$pin]];
        } else {
            $options['verify_peer'] = true;
            $options['verify_host'] = true;
        }

        return $options;
    }

    private static function isTransient(PanelApiException $e): bool
    {
        return in_array($e->failure, [PanelFailure::NETWORK_REFUSED, PanelFailure::NETWORK_TIMEOUT, PanelFailure::NETWORK_OTHER], true)
            || (PanelFailure::HTTP_ERROR === $e->failure && in_array($e->httpStatus, self::RETRY_STATUSES, true));
    }

    /**
     * A failure counts towards "degraded" unless it is the panel refusing one request (success:false) or our own
     * configuration missing.
     */
    private function recordFailure(VpnPanel $panel, PanelApiException $e): void
    {
        if (PanelFailure::PANEL_REJECTED === $e->failure || PanelFailure::NOT_CONFIGURED === $e->failure) {
            return;
        }
        if ($panel->recordCallFailure(new \DateTimeImmutable())) {
            $this->logger->warning('sanaei_panel_degraded', ['panel_id' => $panel->getId(), 'failures' => $panel->getConsecutiveFailures(), 'failure' => $e->failure->value]);
        }
    }

    /**
     * What no message may contain (the token first): the token, the panel's base path (it hides the panel) and every path segment the
     * label replaces (a subscription id opens the customer's subscription).
     *
     * @return list<string>
     */
    private static function secretsOf(PanelEndpoint $endpoint, string $path, ?string $label, #[\SensitiveParameter] string $token): array
    {
        $secrets = [$token];
        $basePath = trim($endpoint->basePath, '/');
        if ('' !== $basePath) {
            $secrets[] = $basePath;
            array_push($secrets, ...explode('/', $basePath));
        }
        if (null !== $label) {
            $shown = explode('/', trim($label, '/'));
            foreach (explode('/', trim($path, '/')) as $segment) {
                if ('' !== $segment && !in_array($segment, $shown, true)) {
                    $secrets[] = $segment;
                    $secrets[] = rawurldecode($segment);
                }
            }
        }
        $words = array_values(array_unique(array_filter(array_slice($secrets, 1), static fn (string $s): bool => '' !== $s)));
        // Longest first: "a/b" before "a".
        usort($words, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return [$token, ...$words];
    }

    /**
     * A text that may quote the request (a transport error, the panel's msg, a Location header) as it may be shown:
     * every URL becomes "<panel>/<label>", each secret is removed wherever it stands as a whole word (also encoded),
     * then the usual token patterns are masked.
     *
     * @param list<string> $secrets
     */
    private static function clean(string $text, #[\SensitiveParameter] array $secrets, string $label, int $maxLength): string
    {
        $text = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://[^\s"\'<>]*#i', '<panel>/'.$label, $text);
        foreach ($secrets as $secret) {
            foreach (array_unique([$secret, rawurlencode($secret)]) as $form) {
                $text = (string) preg_replace('/(?<![A-Za-z0-9_.~-])'.preg_quote($form, '/').'(?![A-Za-z0-9_.~-])/', SecretRedactor::MASK, $text);
            }
        }

        // The token also where it is not a whole word; the base path and segments only as words (they can be short).
        return SecretRedactor::redact($text, array_slice($secrets, 0, 1), $maxLength);
    }

    private function token(VpnPanel $panel): string
    {
        $token = (string) $this->credentials->apiToken($panel);
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
            'path' => SecretRedactor::maskAccess($path), // a client email names the customer's Telegram id (M2c F9)
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
