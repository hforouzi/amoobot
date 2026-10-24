<?php

declare(strict_types=1);

namespace App\Reseller\UI;

use Psr\Cache\CacheItemPoolInterface;

/**
 * "Waiting for a typed number" state of a reseller in the bot (the traffic or the days of a customizable plan), per
 * Telegram user, with a timeout; the same pattern as the admin prompts (AdminPromptStore). Kept a while after it
 * expires so the reseller is told it expired instead of the number being taken for something else.
 */
final class ResellerPromptStore
{
    public const TIMEOUT_SECONDS = 300;
    private const KEEP_SECONDS = 3600;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /**
     * @param string $kind   ResellerBotHandler::PROMPT_*
     * @param string $target what the answer is for (the plan id, and the GB already chosen)
     */
    public function start(string $telegramUserId, string $kind, string $target): void
    {
        $item = $this->cache->getItem($this->key($telegramUserId));
        $item->set(['kind' => $kind, 'target' => $target, 'until' => time() + self::TIMEOUT_SECONDS]);
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
        return 'reseller_bot_prompt_'.preg_replace('/\D/', '', $telegramUserId);
    }
}
