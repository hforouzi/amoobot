<?php

declare(strict_types=1);

namespace App\Bot\Infrastructure;

/**
 * Telegram answered and refused the request (ok=false: blocked, flood limit, bad HTML...): nothing was delivered. Any
 * other failure of TelegramApiClient (transport error, timeout, unreadable answer) leaves the outcome unknown.
 */
final class TelegramApiRejected extends \RuntimeException
{
}
