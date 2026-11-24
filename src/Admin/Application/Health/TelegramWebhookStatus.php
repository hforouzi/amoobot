<?php

declare(strict_types=1);

namespace App\Admin\Application\Health;

use App\Bot\Infrastructure\TelegramApiClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The bot webhook line of the health block (docs/design/admin-redesign.md 4.4). getWebhookInfo is a remote call, so a
 * page load only ever reads the cached result; refresh() runs only from the "check now" POST (SystemHealthController).
 *
 * Only what the line needs is kept: whether a URL is set (never the URL: it carries the webhook secret), the number of
 * pending updates, and Telegram's last delivery error and its time.
 *
 * @phpstan-type Info array{checkedAt: int, reachable: bool, urlSet: bool, pending: int, lastErrorAt: int|null, lastError: string|null}
 */
final class TelegramWebhookStatus
{
    public const CACHE_KEY = 'admin_telegram_webhook_info';
    /** The last result is kept a day and shown with its age. */
    public const TTL = 86400;
    /** A delivery error at most this long before the check counts as current. */
    public const RECENT_ERROR = 3600;

    public const STATE_OK = 'ok';
    public const STATE_PROBLEM = 'problem';
    public const STATE_UNCHECKED = 'unchecked';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly TelegramApiClient $telegram,
        private readonly ClockInterface $clock,
        #[Autowire('%env(default:default_telegram_mode:TELEGRAM_MODE)%')]
        private readonly string $telegramMode = 'webhook',
    ) {
    }

    /** In long-polling mode no webhook may be set (Telegram refuses getUpdates while one is). */
    public function expectsWebhook(): bool
    {
        return 'webhook' === $this->telegramMode;
    }

    /**
     * The last result, or null when nobody checked in the last day. Never calls Telegram.
     *
     * @return Info|null
     */
    public function cached(): ?array
    {
        $info = $this->stored();

        return is_array($info) && is_int($info['checkedAt'] ?? null) ? $info : null;
    }

    /** The raw cache item (null on a miss or a cache failure): whatever is there, checked by cached(). */
    private function stored(): mixed
    {
        try {
            return $this->cache->get(self::CACHE_KEY, static function (ItemInterface $item): mixed {
                $item->expiresAfter(60);

                return null;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Asks Telegram now and keeps the answer. Only from the "check now" POST.
     *
     * @return Info
     */
    public function refresh(): array
    {
        $info = ['checkedAt' => $this->clock->now()->getTimestamp(), 'reachable' => false, 'urlSet' => false, 'pending' => 0, 'lastErrorAt' => null, 'lastError' => null];
        try {
            $data = $this->telegram->getWebhookInfo();
            $result = $data['result'] ?? null;
            if (true === ($data['ok'] ?? null) && is_array($result)) {
                $message = $result['last_error_message'] ?? null;
                $info = [
                    'reachable' => true,
                    'urlSet' => is_string($result['url'] ?? null) && '' !== $result['url'],
                    'pending' => is_int($result['pending_update_count'] ?? null) ? $result['pending_update_count'] : 0,
                    'lastErrorAt' => is_int($result['last_error_date'] ?? null) ? $result['last_error_date'] : null,
                    'lastError' => is_string($message) && '' !== $message ? mb_substr($message, 0, 200) : null,
                ] + $info;
            }
        } catch (\Throwable) {
            // Unreachable: kept as such.
        }
        $this->cache->delete(self::CACHE_KEY);
        $this->cache->get(self::CACHE_KEY, static function (ItemInterface $item) use ($info): array {
            $item->expiresAfter(self::TTL);

            return $info;
        });

        return $info;
    }

    /**
     * @param Info|null $info
     */
    public function state(?array $info): string
    {
        if (null === $info) {
            return self::STATE_UNCHECKED;
        }
        $recentError = null !== $info['lastErrorAt'] && $info['lastErrorAt'] >= $info['checkedAt'] - self::RECENT_ERROR;

        return $info['reachable'] && $info['urlSet'] === $this->expectsWebhook() && !$recentError ? self::STATE_OK : self::STATE_PROBLEM;
    }
}
