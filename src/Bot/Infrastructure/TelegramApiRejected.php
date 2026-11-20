<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure;

/**
 * Telegram answered and refused the request (ok=false: blocked, flood limit, bad HTML...): nothing was delivered. Any
 * other failure of TelegramApiClient (transport error, timeout, unreadable answer) leaves the outcome unknown.
 *
 * $errorCode and $description are Telegram's error_code and description as answered (the description is also in the
 * message, whose text is unchanged).
 */
final class TelegramApiRejected extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly string $description = '',
    ) {
        parent::__construct($message);
    }

    /**
     * True when the bot cannot reach this recipient, so sending the same message again will not help: 403 (bot was
     * blocked, user is deactivated, bot can't initiate conversation), or 400 "chat not found", "user not found" or
     * PEER_ID_INVALID. A flood limit (429), a Telegram server error (5xx) or any other 400 (bad HTML...) is not.
     */
    public function isRecipientUnreachable(): bool
    {
        if (403 === $this->errorCode) {
            return true;
        }
        if (400 !== $this->errorCode) {
            return false;
        }
        $description = strtolower($this->description);

        return str_contains($description, 'chat not found') || str_contains($description, 'user not found') || str_contains($description, 'peer_id_invalid');
    }
}
