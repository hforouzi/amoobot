<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Bot\Application\TelegramKeyboardFactory;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: customers (Telegram accounts).
 */
final class AdminCustomersHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramApiClient $telegramApiClient,
        private readonly TelegramKeyboardFactory $keyboardFactory,
        private readonly AdminBotReplies $replies,
    ) {
    }

    public function recent(string $chatId, ?string $callbackId = null): void
    {
        $accounts = $this->entityManager->getRepository(TelegramAccount::class)->findBy([], ['id' => 'DESC'], 10);
        if ([] === $accounts) {
            $this->replies->popup($chatId, $callbackId, 'کاربری برای نمایش وجود ندارد.', 'popup_no_users');

            return;
        }

        $lines = ["آخرین کاربران:\n"];
        foreach ($accounts as $account) {
            $lines[] = sprintf(
                "User ID: %d\nTelegram ID: %s\nUsername: @%s\nFirst name: %s\nLast activity: %s\n",
                $account->getUser()->getId(),
                $account->getTelegramId(),
                $account->getUsername() ?: '-',
                $account->getFirstName() ?: '-',
                $account->getLastActivityAt()?->format('Y-m-d H:i:s') ?? '-'
            );
        }

        $this->replies->ack($callbackId);
        $this->telegramApiClient->sendMessage($chatId, implode("\n", $lines), $this->keyboardFactory->backToAdminMenu());
    }
}
