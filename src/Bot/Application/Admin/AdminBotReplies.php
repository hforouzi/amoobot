<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use App\Shared\Infrastructure\SafeErrorLog;
use App\Shop\Domain\OrderType;

/**
 * Small reply helpers shared by the bot admin handlers (same behaviour as the TelegramUpdateHandler helpers).
 */
final class AdminBotReplies
{
    public function __construct(private readonly TelegramApiClient $telegramApiClient)
    {
    }

    public function ack(?string $callbackId): void
    {
        if (null === $callbackId || '' === $callbackId) {
            return;
        }

        $this->telegramApiClient->answerCallbackQuery($callbackId);
    }

    /** A popup when answering a button, a message otherwise. */
    public function popup(string $chatId, ?string $callbackId, string $text, string $logKey): void
    {
        if (null !== $callbackId && '' !== $callbackId) {
            $this->log(sprintf('%s callback_alert="%s"', $logKey, $text));
            $this->telegramApiClient->answerCallbackQuery($callbackId, $text, true);

            return;
        }

        $this->log(sprintf('%s text_message="%s"', $logKey, $text));
        $this->telegramApiClient->sendMessage($chatId, $text);
    }

    public function log(string $message): void
    {
        SafeErrorLog::write('[TelegramUpdateHandler] '.$message);
    }

    public function identity(?TelegramAccount $telegramAccount): string
    {
        if (!$telegramAccount instanceof TelegramAccount) {
            return '@- / -';
        }

        return sprintf('@%s / %s', $telegramAccount->getUsername() ?: '-', $telegramAccount->getTelegramId());
    }

    public function orderType(string $type): string
    {
        return match ($type) {
            OrderType::RENEWAL => 'تمدید',
            OrderType::ADD_TRAFFIC => 'خرید حجم',
            OrderType::TRIAL => 'اکانت تست',
            default => 'خرید سرویس',
        };
    }

    public function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
