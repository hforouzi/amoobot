<?php

declare(strict_types=1);

/*
 * Test helper: one Tetraminator notify request, through the real kernel (routing, firewall, controller), in its own PHP
 * process (own DB connection, own lock wait). The inquiry answers come from the gateway's script
 * (ScriptedTetraminatorPlugin), which processes share.
 * Usage: php tetraminator-notify.php <paymentId> <token> <customerChatId> <startAt>
 * Prints "<page|other>:<messages sent to the customer by this process>".
 */

use App\Payment\UI\TetraminatorNotifyController;
use App\Tests\Support\MockHttpResponder;
use Symfony\Component\HttpFoundation\Request;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';
require __DIR__.'/start-at.php';

// From $_SERVER['argv'], checked: phpstan cannot tell that $argv is defined here.
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) < 5) {
    fwrite(STDERR, "usage: tetraminator-notify.php <paymentId> <token> <customerChatId> <startAt>\n");
    exit(2);
}
[, $paymentId, $token, $chatId, $startAt] = array_map(strval(...), $arguments);
waitForStartAt($startAt);

$request = Request::create('/payment/notify/tetraminator/'.$paymentId.'/'.$token, 'GET', server: ['REMOTE_ADDR' => '10.79.'.random_int(0, 255).'.'.random_int(1, 254)]);
$response = $container->get('kernel')->handle($request);

$messages = 0;
foreach ($container->get(MockHttpResponder::class)->requests('/sendMessage') as $sent) {
    $body = json_decode((string) ($sent['options']['body'] ?? ''), true);
    $messages += is_array($body) && $chatId === (string) ($body['chat_id'] ?? '') ? 1 : 0;
}

echo (200 === $response->getStatusCode() && TetraminatorNotifyController::PAGE === $response->getContent() ? 'page' : 'other').':'.$messages;
