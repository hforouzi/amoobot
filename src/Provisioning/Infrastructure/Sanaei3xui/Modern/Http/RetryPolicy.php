<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\ApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\NetworkException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\PanelUnavailableException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\TimeoutException;

final class RetryPolicy
{
    public function maxAttempts(VpnPanel $panel): int
    {
        $config = is_array($panel->getConfig()) ? $panel->getConfig() : [];
        $configured = $this->nonNegativeInt($config['modern_retry_count'] ?? null);
        $retryCount = null === $configured ? 1 : min($configured, 5);

        return $retryCount + 1;
    }

    public function shouldRetry(ApiException $exception, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        if ($exception instanceof TimeoutException) {
            return true;
        }

        if ($exception instanceof NetworkException && !$exception instanceof TimeoutException) {
            return true;
        }

        if ($exception instanceof PanelUnavailableException && 503 === $exception->statusCode) {
            return true;
        }

        return false;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ('' === $text || !ctype_digit($text)) {
            return null;
        }

        $int = (int) $text;

        return max(0, $int);
    }
}
