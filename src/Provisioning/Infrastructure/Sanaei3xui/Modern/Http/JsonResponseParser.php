<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidJsonException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;

final class JsonResponseParser
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function parse(int $statusCode, array $headers, string $rawResponse, string $requestId): JsonResponse
    {
        if (204 === $statusCode) {
            return new JsonResponse(null, '', $statusCode, $headers, $requestId);
        }

        $contentType = strtolower((string) ($this->firstHeader($headers, 'content-type') ?? ''));
        if ('' !== $contentType && !str_contains($contentType, 'application/json') && !str_contains($contentType, '+json')) {
            throw new InvalidResponseException('Sanaei Modern response content type is not JSON.', $statusCode, $requestId);
        }

        $body = trim($rawResponse);
        if ('' === $body) {
            throw new InvalidResponseException('Sanaei Modern response body is empty.', $statusCode, $requestId);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidJsonException('Sanaei Modern response body is not valid JSON.', $statusCode, $requestId, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidResponseException('Sanaei Modern response JSON must be an object or array.', $statusCode, $requestId);
        }

        return new JsonResponse($decoded, $rawResponse, $statusCode, $headers, $requestId);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $values) {
            if (0 === strcasecmp($headerName, $name)) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
