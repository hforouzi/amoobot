<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * The bot's @username, for t.me deep links (claim links). Read from Telegram (getMe) and cached for a day; a failed
 * lookup is cached for a few minutes only, so a short outage does not hide the links for long. Null when unknown: the
 * caller then gives the payload without a link.
 */
class BotIdentity
{
    private const CACHE_KEY = 'bot_identity_username';
    private const TTL_SECONDS = 86400;
    private const FAILURE_TTL_SECONDS = 300;

    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function username(): ?string
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $value = $item->get();

            return is_string($value) && '' !== $value ? $value : null;
        }
        $username = null;
        try {
            $candidate = (string) ($this->telegram->getMe()['username'] ?? '');
            // Telegram usernames: 5-32 characters of [A-Za-z0-9_]; anything else is not put into a link.
            $username = 1 === preg_match('/^[A-Za-z0-9_]{5,32}$/D', $candidate) ? $candidate : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Bot username lookup (getMe) failed.', ['error' => $e->getMessage()]);
        }
        $item->set($username ?? '');
        $item->expiresAfter(null === $username ? self::FAILURE_TTL_SECONDS : self::TTL_SECONDS);
        $this->cache->save($item);

        return $username;
    }
}
