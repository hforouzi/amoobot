<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SanaeiModernHttpTransport implements ModernHttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SanaeiModernHttpRequestBuilder $requestBuilder,
        private readonly JsonResponseParser $jsonResponseParser,
        private readonly SanaeiEnvelopeValidator $envelopeValidator,
        private readonly ExceptionMapper $exceptionMapper,
        private readonly RetryPolicy $retryPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function request(VpnPanel $panel, string $method, string $path, mixed $payload = null, array $headers = []): HttpResult
    {
        $request = $this->requestBuilder->build($panel, $method, $path, $payload, $headers);
        $maxAttempts = $this->retryPolicy->maxAttempts($panel);
        $attempt = 0;

        do {
            ++$attempt;
            $startedAt = microtime(true);
            $loggedFailure = false;
            try {
                $response = $this->httpClient->request($request->method, $request->url, $request->toSymfonyOptions());
                $statusCode = $response->getStatusCode();
                $responseHeaders = $response->getHeaders(false);
                $rawResponse = $response->getContent(false);
                $durationMs = $this->durationMs($startedAt);

                $statusException = $this->exceptionMapper->mapStatus(
                    $statusCode,
                    $request->requestId,
                    $this->extractErrorMessage($rawResponse)
                );

                if ($statusException instanceof ApiException) {
                    $this->logFailure($panel, $request, $attempt, $durationMs, $statusCode, $statusException);
                    $loggedFailure = true;
                    if ($this->retryPolicy->shouldRetry($statusException, $attempt, $maxAttempts)) {
                        continue;
                    }

                    throw $statusException;
                }

                $jsonResponse = $this->jsonResponseParser->parse($statusCode, $responseHeaders, $rawResponse, $request->requestId);
                $result = $this->envelopeValidator->toHttpResult($jsonResponse);
                $this->logSuccess($panel, $request, $attempt, $durationMs, $statusCode);

                return $result;
            } catch (ApiException $exception) {
                if (!$loggedFailure) {
                    $this->logFailure($panel, $request, $attempt, $this->durationMs($startedAt), $exception->statusCode, $exception);
                }
                if ($this->retryPolicy->shouldRetry($exception, $attempt, $maxAttempts)) {
                    continue;
                }

                throw $exception;
            } catch (\Throwable $exception) {
                $mapped = $this->exceptionMapper->mapTransport($exception, $request->requestId);
                $this->logFailure($panel, $request, $attempt, $this->durationMs($startedAt), null, $mapped);
                if ($this->retryPolicy->shouldRetry($mapped, $attempt, $maxAttempts)) {
                    continue;
                }

                throw $mapped;
            }
        } while ($attempt < $maxAttempts);

        throw new ApiException('Sanaei Modern API request failed after retries.', null, $request->requestId);
    }

    private function logSuccess(VpnPanel $panel, ModernHttpRequest $request, int $attempt, int $durationMs, int $statusCode): void
    {
        $this->logger->debug('sanaei_modern_http_request', [
            'panel_id' => $panel->getId(),
            'request_id' => $request->requestId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $statusCode,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
            'outcome' => 'success',
        ]);
    }

    private function logFailure(VpnPanel $panel, ModernHttpRequest $request, int $attempt, int $durationMs, ?int $statusCode, ApiException $exception): void
    {
        $this->logger->warning('sanaei_modern_http_request', [
            'panel_id' => $panel->getId(),
            'request_id' => $request->requestId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $statusCode,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
            'outcome' => 'failure',
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function extractErrorMessage(string $rawResponse): ?string
    {
        try {
            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return isset($decoded['msg']) && is_scalar($decoded['msg']) ? (string) $decoded['msg'] : null;
    }
}
