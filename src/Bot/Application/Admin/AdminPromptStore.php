<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use Psr\Cache\CacheItemPoolInterface;

/**
 * "Waiting for a value" state of an admin in the bot (new plan price, a setting, a search text, ...), per Telegram
 * user, with a timeout. Kept a while after it expires so the admin is told it expired instead of the text being
 * treated as a customer message.
 */
final class AdminPromptStore
{
    public const TIMEOUT_SECONDS = 300;
    private const KEEP_SECONDS = 3600;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function start(string $telegramUserId, string $kind, string $target, int $timeoutSeconds = self::TIMEOUT_SECONDS): void
    {
        $item = $this->cache->getItem($this->key($telegramUserId));
        $item->set(['kind' => $kind, 'target' => $target, 'until' => time() + $timeoutSeconds]);
        $item->expiresAfter(self::KEEP_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @return array{kind: string, target: string, expired: bool}|null
     */
    public function get(string $telegramUserId): ?array
    {
        $item = $this->cache->getItem($this->key($telegramUserId));
        $value = $item->isHit() ? $item->get() : null;
        if (!is_array($value) || !isset($value['kind'], $value['target'], $value['until'])) {
            return null;
        }

        return ['kind' => (string) $value['kind'], 'target' => (string) $value['target'], 'expired' => time() > (int) $value['until']];
    }

    public function clear(string $telegramUserId): void
    {
        $this->cache->deleteItem($this->key($telegramUserId));
    }

    private function key(string $telegramUserId): string
    {
        return 'admin_bot_prompt_'.preg_replace('/\D/', '', $telegramUserId);
    }
}
