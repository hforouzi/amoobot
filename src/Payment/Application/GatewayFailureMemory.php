<?php

declare(strict_types=1);

namespace App\Payment\Application;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The last credential failure of each gateway in the past 24 h (code and time), for the admin's health block
 * (docs/design/admin-redesign.md 4.4). Written by PaymentGatewayCredentialAlert on every such failure, before its
 * hourly alert limit; one cache item for all gateways, so the dashboard reads it with one cache read and no query.
 * Only the fixed code is kept (never a provider message), plus the gateway's title for display.
 *
 * @phpstan-type Failure array{gatewayId: int, title: string, code: string, at: int}
 */
final class GatewayFailureMemory
{
    public const CACHE_KEY = 'admin_gateway_credential_failures';
    public const TTL = 86400;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(int $gatewayId, string $title, string $code): void
    {
        $now = $this->clock->now()->getTimestamp();
        try {
            $failures = $this->recent();
            $failures[$gatewayId] = ['gatewayId' => $gatewayId, 'title' => mb_substr($title, 0, 120), 'code' => $code, 'at' => $now];
            $this->cache->delete(self::CACHE_KEY);
            $this->cache->get(self::CACHE_KEY, static function (ItemInterface $item) use ($failures): array {
                $item->expiresAfter(self::TTL);

                return $failures;
            });
        } catch (\Throwable $e) {
            $this->logger->error('Gateway failure not remembered.', ['gateway_id' => $gatewayId, 'error' => $e::class]);
        }
    }

    /**
     * Failures of the last 24 h, newest first, keyed by gateway id.
     *
     * @return array<int, Failure>
     */
    public function recent(): array
    {
        $stored = $this->stored();
        $since = $this->clock->now()->getTimestamp() - self::TTL;
        $failures = [];
        foreach (is_array($stored) ? $stored : [] as $failure) {
            if (is_array($failure) && is_int($failure['gatewayId'] ?? null) && is_string($failure['title'] ?? null)
                && is_string($failure['code'] ?? null) && is_int($failure['at'] ?? null) && $failure['at'] > $since) {
                $failures[$failure['gatewayId']] = ['gatewayId' => $failure['gatewayId'], 'title' => $failure['title'], 'code' => $failure['code'], 'at' => $failure['at']];
            }
        }
        uasort($failures, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $failures;
    }

    /** The raw cache item ([] on a miss or a cache failure): whatever is there, checked by recent(). */
    private function stored(): mixed
    {
        try {
            return $this->cache->get(self::CACHE_KEY, static function (ItemInterface $item): mixed {
                $item->expiresAfter(60);

                return [];
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
