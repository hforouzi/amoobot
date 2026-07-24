<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\ApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthenticationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\AuthorizationFailedException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\NetworkException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\PanelUnavailableException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\RateLimitException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class ExceptionMapper
{
    public function mapStatus(int $statusCode, string $requestId, ?string $message = null): ?ApiException
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return null;
        }

        $message = $this->sanitizeMessage($message);

        return match ($statusCode) {
            401 => new AuthenticationFailedException($message ?: 'Sanaei Modern authentication failed.', $statusCode, $requestId),
            403 => new AuthorizationFailedException($message ?: 'Sanaei Modern API access is forbidden.', $statusCode, $requestId),
            429 => new RateLimitException($message ?: 'Sanaei Modern API rate limit was reached.', $statusCode, $requestId),
            500, 502, 503 => new PanelUnavailableException($message ?: sprintf('Sanaei Modern panel is unavailable (HTTP %d).', $statusCode), $statusCode, $requestId),
            400 => new ApiException($message ?: 'Sanaei Modern API rejected the request.', $statusCode, $requestId),
            404 => new ApiException($message ?: 'Sanaei Modern API resource was not found.', $statusCode, $requestId),
            409 => new ApiException($message ?: 'Sanaei Modern API reported a conflict.', $statusCode, $requestId),
            422 => new ApiException($message ?: 'Sanaei Modern API validation failed.', $statusCode, $requestId),
            default => new ApiException($message ?: sprintf('Sanaei Modern API request failed (HTTP %d).', $statusCode), $statusCode, $requestId),
        };
    }

    public function mapTransport(\Throwable $exception, string $requestId): NetworkException
    {
        $message = $this->sanitizeMessage($exception->getMessage());
        if ($this->looksLikeTimeout($message)) {
            return new TimeoutException('Sanaei Modern API request timed out.', null, $requestId, $exception);
        }

        if ($exception instanceof TransportExceptionInterface) {
            return new NetworkException($message ?: 'Sanaei Modern API transport failure.', null, $requestId, $exception);
        }

        return new NetworkException($message ?: 'Sanaei Modern API network failure.', null, $requestId, $exception);
    }

    private function looksLikeTimeout(string $message): bool
    {
        return 1 === preg_match('/timeout|timed out|deadline|operation aborted/i', $message);
    }

    private function sanitizeMessage(?string $message): string
    {
        $safe = trim((string) ($message ?? ''));
        $safe = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [redacted]', $safe) ?? $safe;
        $safe = preg_replace('/([?&](?:api[_-]?token|access[_-]?token|token|password|passwd|cookie|session|authorization)=)[^&#\s]*/i', '$1[redacted]', $safe) ?? $safe;
        $safe = preg_replace('/("?(?:password|passwd|token|cookie|session|authorization|secret|api[_-]?key)"?\s*[:=]\s*)"[^"]*"/i', '$1"[redacted]"', $safe) ?? $safe;
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;

        return mb_substr($safe, 0, 300);
    }
}
