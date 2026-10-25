<?php

declare(strict_types=1);

/*
 * Test helper: one bot text message (e.g. "/start claim_...") in its own PHP process, as a second delivery of the same
 * Telegram update would be handled. Usage: php bot-message.php <telegramId> <updateId> <text> [startAtUnixMicrotime]
 */

use App\Bot\Application\TelegramUpdateHandler;

[$container] = require __DIR__.'/boot-test-kernel.php';

$telegramId = (int) ($argv[1] ?? 0);
$wait = (float) ($argv[4] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
$container->get(TelegramUpdateHandler::class)->handle([
    'update_id' => (int) ($argv[2] ?? 1),
    'message' => [
        'message_id' => 7,
        'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
        'chat' => ['id' => $telegramId, 'type' => 'private'],
        'date' => time(),
        'text' => (string) ($argv[3] ?? ''),
    ],
]);
echo 'done';
