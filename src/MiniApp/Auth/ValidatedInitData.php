<?php

declare(strict_types=1);

namespace App\MiniApp\Auth;

/**
 * initData whose HMAC and age were checked (InitDataValidator). The only source of who the Mini App user is: the
 * client's initDataUnsafe is never read.
 */
final class ValidatedInitData
{
    /**
     * @param array<string, mixed> $user the Telegram user object (id, first_name, last_name, username, language_code, ...)
     */
    public function __construct(
        public readonly array $user,
        public readonly int $authDate,
        public readonly ?string $startParam,
    ) {
    }

    public function telegramUserId(): string
    {
        return (string) $this->user['id'];
    }
}
