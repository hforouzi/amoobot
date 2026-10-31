<?php

declare(strict_types=1);

/*
 * Test helper (M2e E1): one step in its own PHP process against the fake 3x-ui shared through <stateFile>, every panel
 * request delayed by <delayMs>. Every Telegram call is appended to <sentFile> as one JSON line
 * {"pid", "chat_id", "method", "text"}, so a test can count the customer's messages across processes.
 *
 * Usage: php delivery.php <mode> <id> <stateFile> <delayMs> <sentFile> [scenario,...]
 * Modes: settle <paymentId> (prints the outcome) | resend-job - (prints "resent=N") | attach-scheduled - (prints "attached=N")
 * Scenarios:
 * - primary-only: the sale's add lands on the primary only (the pass after the commit attaches the others);
 * - attach-refused: every attach call is refused (the pass attaches nothing);
 * - telegram-down: the customer's first message fails (Telegram answers ok=false), later ones go through;
 * - die-in-send: the process exits while sending the customer's first message, before Telegram has it.
 */

use App\Entity\Payment;
use App\Payment\Application\MissingDeliveryResender;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\MockHttpResponder;
use Symfony\Component\HttpClient\Response\MockResponse;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';

[$mode, $id, $stateFile, $delayMs, $sentFile, $scenarios] = array_slice($argv ?? [], 1) + array_fill(0, 6, '');
$scenarios = array_filter(explode(',', $scenarios));

$fake = new Fake3xuiPanel();
$fake->stateFile = $stateFile;
$fake->delays = ['(GET|POST) .*' => (int) $delayMs];
$http = $container->get(MockHttpResponder::class);
$http->on('panel.example.test', $fake);
$adminChat = (string) ($_SERVER['TELEGRAM_ADMIN_CHAT_ID'] ?? $_ENV['TELEGRAM_ADMIN_CHAT_ID'] ?? '');
$customerSends = 0;
$http->on('api.telegram.org', static function (string $method, string $url, array $options) use ($sentFile, $scenarios, $adminChat, &$customerSends): MockResponse {
    $data = json_decode((string) ($options['body'] ?? ''), true) ?: [];
    $chat = (string) ($data['chat_id'] ?? '');
    if ('' !== $chat && $chat !== $adminChat && 0 === $customerSends++) {
        if (in_array('die-in-send', $scenarios, true)) {
            exit(9);
        }
        if (in_array('telegram-down', $scenarios, true)) {
            return new MockResponse('{"ok":false,"description":"Too Many Requests"}', ['response_headers' => ['content-type' => 'application/json']]);
        }
    }
    file_put_contents($sentFile, json_encode(['pid' => getmypid(), 'chat_id' => $chat, 'method' => basename((string) parse_url($url, PHP_URL_PATH)), 'text' => (string) ($data['text'] ?? '')], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

    return new MockResponse('{"ok":true,"result":{"message_id":1}}', ['response_headers' => ['content-type' => 'application/json']]);
});

if (in_array('primary-only', $scenarios, true)) {
    $fake->script('POST', 'panel/api/clients/add', static function (string $m, string $u, array $options) use ($fake): MockResponse {
        $body = json_decode((string) $options['body'], true);
        $fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

        return Fake3xuiPanel::envelope(true);
    });
}
if (in_array('attach-refused', $scenarios, true)) {
    // Registered after the add's script: every POST after the add is an attach.
    $fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'refused'), 50);
}

echo match ($mode) {
    'settle' => $container->get(PaymentSettlementService::class)->approveByAdmin($entityManager->find(Payment::class, (int) $id) ?? throw new RuntimeException('no such payment'), 1, null, 'concurrency_test')->outcome->value,
    'resend-job' => 'resent='.$container->get(MissingDeliveryResender::class)->run(50)['resent'],
    'attach-scheduled' => 'attached='.$container->get(InboundAttachScheduler::class)->run(500, false, 50, 0)->attached,
    default => throw new RuntimeException('unknown mode '.$mode),
};
