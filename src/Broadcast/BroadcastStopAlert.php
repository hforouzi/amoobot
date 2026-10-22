<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Bot\Application\BotTextResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\BroadcastMessage;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;

/**
 * Tells the admins who send broadcasts (BROADCAST_SEND, in Telegram) that BroadcastSender stopped one because
 * Telegram could not parse its text, with Telegram's reason and what to do (bot text admin.broadcasts.stopped_format).
 */
final class BroadcastStopAlert
{
    public function __construct(
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly BotTextResolver $texts,
    ) {
    }

    public function formatError(BroadcastMessage $broadcast, string $error): void
    {
        $text = $this->texts->message('admin.broadcasts.stopped_format', [
            'id' => (int) $broadcast->getId(),
            'title' => $broadcast->getTitle(),
            'parse_mode' => (string) $broadcast->getParseMode(),
            'reason' => self::reason($error),
            'failed' => $broadcast->getFailedCount(),
            'pending' => max(0, $broadcast->getTotalRecipients() - $broadcast->getSentCount() - $broadcast->getFailedCount() - $broadcast->getSkippedCount()),
        ]);
        foreach ($this->accessPolicy->telegramRecipients(AdminPermission::BROADCAST_SEND) as $chat) {
            $this->telegramApiClient->sendMessage($chat, $text);
        }
    }

    /** Telegram's explanation only ("Character '.' is reserved ..."), without the API call around it. */
    public static function reason(string $error): string
    {
        $reason = 1 === preg_match("/can't parse entities:\\s*(.+)/is", $error, $match) ? trim($match[1]) : $error;

        return mb_substr($reason, 0, 300);
    }
}
