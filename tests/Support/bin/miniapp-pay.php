<?php

declare(strict_types=1);

/*
 * Test helper: one POST /app/api/orders/{id}/pay of the Mini App, through the kernel (firewall, rate limit,
 * Idempotency-Key, controller), in its own PHP process (own DB connection, own lock wait), so a test can race it.
 * The session is one the test opened with /app/auth.
 * Usage: php miniapp-pay.php <orderId> <methodId> <sessionToken> <csrfToken> <idempotencyKey> <startAt>
 * Prints "<HTTP status> <milliseconds from its start to its answer> <JSON answer>".
 */

use Symfony\Component\HttpFoundation\Request;

[$container] = require __DIR__.'/boot-test-kernel.php';
require __DIR__.'/start-at.php';

[$orderId, $methodId, $token, $csrf, $key, $startAt] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), (string) ($argv[5] ?? ''), (string) ($argv[6] ?? '')];
$request = Request::create('/app/api/orders/'.$orderId.'/pay', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    'HTTP_X_CSRF_TOKEN' => $csrf,
    'HTTP_IDEMPOTENCY_KEY' => $key,
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) Telegram-Android/11.2',
    'CONTENT_TYPE' => 'application/json',
], json_encode(['methodId' => $methodId], JSON_THROW_ON_ERROR));
$kernel = $container->get('kernel');

$started = waitForStartAt($startAt);
$response = $kernel->handle($request);
printf('%d %d %s', $response->getStatusCode(), (int) round((microtime(true) - $started) * 1000), (string) $response->getContent());
