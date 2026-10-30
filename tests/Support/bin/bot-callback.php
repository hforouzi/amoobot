<?php

declare(strict_types=1);

/*
 * Test helper: one bot button press (callback query) in its own PHP process (own DB connection), as a parallel webhook
 * request would handle it, so a test can race two taps. Telegram itself is the test HTTP mock.
 * Usage: php bot-callback.php <telegramId> <callbackData> [startAt (bin/start-at.php)]
 */

use App\Bot\Application\TelegramUpdateHandler;

[$container] = require __DIR__.'/boot-test-kernel.php';

$telegramId = (int) ($argv[1] ?? 0);
require_once __DIR__.'/start-at.php';
waitForStartAt((string) ($argv[3] ?? '0'));

$container->get(TelegramUpdateHandler::class)->handle([
    'update_id' => random_int(1, PHP_INT_MAX),
    'callback_query' => [
        'id' => 'cb'.random_int(1, PHP_INT_MAX),
        'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
        'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
        'data' => (string) ($argv[2] ?? ''),
    ],
]);

echo 'done';
