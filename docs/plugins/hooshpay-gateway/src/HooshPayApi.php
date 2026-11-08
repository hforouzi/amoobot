<?php

declare(strict_types=1);

namespace Amoobot\Plugin\HooshPay;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP layer for the HooshPay REST API (https://hooshpay.xyz/developers).
 *
 * TLS verification stays on, redirects are not followed, the body is capped and anything that is not a JSON object
 * (HTML error pages, empty bodies) becomes a HooshPayException, except for the statuses in STATUS_ONLY, which are
 * returned by their status alone. Nothing is retried: create has no idempotency key, so a second create could leave a
 * second invoice. The HTTP status is returned with the body: the plugin decides.
 */
final class HooshPayApi
{
    public const DEFAULT_BASE_URL = 'https://hooshpay.xyz';
    public const INVOICES_PATH = '/api/v1/invoices';

    /**
     * Symfony HttpClient does not allow a separate connect timeout (CURLOPT_CONNECTTIMEOUT is blocked), so this is an
     * inactivity timeout: it bounds a stalled connect and a silent server.
     */
    public const IDLE_TIMEOUT = 5.0;
    public const MAX_DURATION = 15.0;
    public const MAX_RESPONSE_BYTES = 65536;
    /** 401 (invalid key), 403 (account blocked) and 503 (gateway disabled / no card): decided by the status, never parsed. */
    public const STATUS_ONLY = [401, 403, 503];

    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    /**
     * @param array<string, string|int> $json
     *
     * @return array{status: int, body: array<mixed>}
     *
     * @throws HooshPayException
     */
    public function post(string $baseUrl, string $path, #[\SensitiveParameter] string $apiKey, array $json): array
    {
        return $this->request('POST', $baseUrl, $path, $apiKey, $json);
    }

    /**
     * @return array{status: int, body: array<mixed>}
     *
     * @throws HooshPayException
     */
    public function get(string $baseUrl, string $path, #[\SensitiveParameter] string $apiKey): array
    {
        return $this->request('GET', $baseUrl, $path, $apiKey, null);
    }

    /** The path of one invoice, with the uid encoded. */
    public static function invoicePath(string $uid, string $action = ''): string
    {
        return self::INVOICES_PATH.'/'.rawurlencode($uid).('' === $action ? '' : '/'.$action);
    }

    /**
     * @param array<string, string|int>|null $json
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function request(string $method, string $baseUrl, string $path, #[\SensitiveParameter] string $apiKey, ?array $json): array
    {
        $client = $this->httpClient ?? HttpClient::create();
        $options = [
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
            'headers' => ['Accept' => 'application/json', 'X-API-KEY' => $apiKey],
            // Aborts the download as soon as the body grows past the cap; a status-only body is never read.
            'on_progress' => static function (int $downloaded, int $size, array $info): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES && !in_array($info['http_code'] ?? 0, self::STATUS_ONLY, true)) {
                    throw new HooshPayException('HooshPay response is too large.');
                }
            },
        ];
        if (null !== $json) {
            // JSON_UNESCAPED_UNICODE: a Persian description is sent as UTF-8, not as \u escapes.
            $options['body'] = (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        }

        try {
            $response = $client->request($method, rtrim($baseUrl, '/').$path, $options);
            $httpStatus = $response->getStatusCode();
            if (in_array($httpStatus, self::STATUS_ONLY, true)) {
                // Decided by the status alone, whatever the body is (empty, HTML, JSON): it is not read.
                $response->cancel();

                return ['status' => $httpStatus, 'body' => []];
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            if ($exception->getPrevious() instanceof HooshPayException) {
                throw $exception->getPrevious();
            }
            // The transport's own text names the URL (an invoice uid): it is not passed on.
            if (str_contains($exception->getMessage(), 'timeout') || str_contains($exception->getMessage(), 'Max duration')) {
                throw new HooshPayException('HooshPay did not respond in time.', true, $exception);
            }
            throw new HooshPayException('Could not reach HooshPay (network or TLS error).', false, $exception);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new HooshPayException('HooshPay response is too large.');
        }

        $body = trim($body);
        $decoded = '' === $body ? null : json_decode($body, true, 32);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new HooshPayException(sprintf(
                '' === $body ? 'HooshPay returned an empty response (HTTP %d).'
                    : (str_starts_with($body, '<') ? 'HooshPay returned an HTML page instead of JSON (HTTP %d).' : 'HooshPay returned an invalid response (HTTP %d).'),
                $httpStatus,
            ));
        }

        return ['status' => $httpStatus, 'body' => $decoded];
    }
}
