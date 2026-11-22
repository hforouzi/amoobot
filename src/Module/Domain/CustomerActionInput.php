<?php

declare(strict_types=1);

namespace App\Module\Domain;

use App\Entity\TelegramAccount;

/**
 * Who runs a customer action and where its answer goes (2.2's ActionInput). PM1d: today's delivery is the customer's
 * chat with the bot, from the bot or from the Mini App, so this is the Telegram account, the chat and the callback
 * to answer ('' when there is none, as from the Mini App).
 */
final readonly class CustomerActionInput
{
    public function __construct(
        public TelegramAccount $account,
        public string $chatId,
        public string $callbackId = '',
    ) {
    }
}
