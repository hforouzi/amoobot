<?php

declare(strict_types=1);

namespace App\Broadcast;

use App\Entity\TelegramAccount;
use App\Entity\User;

final readonly class BroadcastRecipient
{
    public function __construct(
        public string $chatId,
        public ?TelegramAccount $telegramAccount = null,
        public ?User $user = null,
    ) {
    }
}
