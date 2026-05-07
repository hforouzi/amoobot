<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use App\Entity\TelegramAccount;

final class ServiceActionContext
{
    public function __construct(
        public readonly TelegramAccount $account,
        public readonly string $actorId,
        public readonly string $chatId,
        public readonly string $callbackId,
        public readonly string $data,
        public readonly bool $isAdmin,
    ) {
    }
}
