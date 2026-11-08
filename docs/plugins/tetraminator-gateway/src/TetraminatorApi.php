<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Tetraminator;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP layer for the Tetraminator REST API (docs/plugins/tetraminator-gateway/design.md, decision 9).
 *
 * TLS verification stays on, redirects are not followed, the body is capped and anything that is not a JSON object
 * (HTML error pages, empty bodies) becomes a TetraminatorException. Nothing is retried: the API has no idempotency key,
 * so a second create could leave a second invoice. The HTTP status is returned with the body: the plugin decides.
 */
final class TetraminatorApi
{
    public const BASE_URL = 'https://api.tetraminator.com/v1';
    public const CREATE_PATH = '/invoice/create';
    public const INQUIRY_PATH = '/payment/inquiry/';

    /**
     * Symfony HttpClient does not allow a separate connect timeout (CURLOPT_CONNECTTIMEOUT is blocked), so this is an
     * inactivity timeout: it bounds a stalled connect and a silent server.
     */
    public const IDLE_TIMEOUT = 5.0;
    public const MAX_DURATION = 15.0;
    public const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    /**
     * @param array<string, string|int> $json
     *
     * @return array{status: int, body: array<mixed>}
     *
     * @throws TetraminatorException
     */
    public function post(string $path, #[\SensitiveParameter] string $apiKey, array $json): array
    {
        return $this->request('POST', $path, $apiKey, $json);
    }

    /**
     * @return array{status: int, body: array<mixed>}
     *
     * @throws TetraminatorException
     */
    public function get(string $path, #[\SensitiveParameter] string $apiKey): array
    {
        return $this->request('GET', $path, $apiKey, null);
    }

    /**
     * @param array<string, string|int>|null $json
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function request(string $method, string $path, #[\SensitiveParameter] string $apiKey, ?array $json): array
    {
        $client = $this->httpClient ?? HttpClient::create();
        $options = [
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
            'headers' => ['Accept' => 'application/json', 'X-API-KEY' => $apiKey],
            // Aborts the download as soon as the body grows past the cap.
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new TetraminatorException('Tetraminator response is too large.');
                }
            },
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $client->request($method, self::BASE_URL.$path, $options);
            $httpStatus = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            if ($exception->getPrevious() instanceof TetraminatorException) {
                throw $exception->getPrevious();
            }
            // The transport's own text names the URL (a pay_id for an inquiry): it is not passed on.
            if (str_contains($exception->getMessage(), 'timeout') || str_contains($exception->getMessage(), 'Max duration')) {
                throw new TetraminatorException('Tetraminator did not respond in time.', previous: $exception);
            }
            throw new TetraminatorException('Could not reach Tetraminator (network or TLS error).', previous: $exception);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new TetraminatorException('Tetraminator response is too large.');
        }

        $body = trim($body);
        $decoded = '' === $body ? null : json_decode($body, true, 32);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new TetraminatorException(sprintf(
                '' === $body ? 'Tetraminator returned an empty response (HTTP %d).'
                    : (str_starts_with($body, '<') ? 'Tetraminator returned an HTML page instead of JSON (HTTP %d).' : 'Tetraminator returned an invalid response (HTTP %d).'),
                $httpStatus,
            ));
        }

        return ['status' => $httpStatus, 'body' => $decoded];
    }
}
