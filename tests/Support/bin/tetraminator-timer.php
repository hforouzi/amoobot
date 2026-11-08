<?php

declare(strict_types=1);

/*
 * Test helper: one Tetraminator timer run (TetraminatorReconciler::runDue()) at a given time, in its own PHP process (own
 * DB connection, own lock wait), to race it against a notify request (tetraminator-notify.php). The inquiry answers
 * come from the gateway's script (ScriptedTetraminatorPlugin), which processes share.
 * Usage: php tetraminator-timer.php <nowTimestamp> <customerChatId> <startAt>
 * Prints "<settled>:<expired>:<messages sent to the customer by this process>".
 */

use App\Payment\Application\Reconcile\TetraminatorReconciler;
use App\Tests\Support\MockHttpResponder;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';
require __DIR__.'/start-at.php';

// From $_SERVER['argv'], checked: phpstan cannot tell that $argv is defined here.
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) < 4) {
    fwrite(STDERR, "usage: tetraminator-timer.php <nowTimestamp> <customerChatId> <startAt>\n");
    exit(2);
}
[, $now, $chatId, $startAt] = array_map(strval(...), $arguments);
waitForStartAt($startAt);

$run = $container->get(TetraminatorReconciler::class)->runDue((new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone(date_default_timezone_get())));

$messages = 0;
foreach ($container->get(MockHttpResponder::class)->requests('/sendMessage') as $sent) {
    $body = json_decode((string) ($sent['options']['body'] ?? ''), true);
    $messages += is_array($body) && $chatId === (string) ($body['chat_id'] ?? '') ? 1 : 0;
}

echo $run->settled.':'.$run->expired.':'.$messages;
