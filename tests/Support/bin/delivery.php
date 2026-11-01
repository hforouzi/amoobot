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
 * - die-in-send: the process exits while sending the customer's first message, before Telegram has it;
 * - die-after-send: Telegram accepts the customer's first message (it is logged), then the process exits before the
 *   answer is read (killed between Telegram's acceptance and the recording);
 * - timeout-after-accept: Telegram accepts the customer's first message, the answer is lost (transport timeout);
 * - always-timeout: the same for every customer message of this process;
 * - fail-second: Telegram refuses the customer's second message (ok=false), the others go through;
 * - parse-error: Telegram refuses every customer message sent with parse_mode HTML ("can't parse entities");
 * - em-closed: the entity manager is closed before the step runs (an earlier task's flush failed).
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
    $accept = static function () use ($sentFile, $chat, $url, $data): void {
        file_put_contents($sentFile, json_encode(['pid' => getmypid(), 'chat_id' => $chat, 'method' => basename((string) parse_url($url, PHP_URL_PATH)), 'text' => (string) ($data['text'] ?? ''), 'html' => isset($data['parse_mode'])], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    };
    $refuse = static fn (string $description): MockResponse => new MockResponse((string) json_encode(['ok' => false, 'description' => $description]), ['response_headers' => ['content-type' => 'application/json']]);
    if ('' !== $chat && $chat !== $adminChat) {
        $n = $customerSends++;
        if (in_array('parse-error', $scenarios, true) && isset($data['parse_mode'])) {
            return $refuse("Bad Request: can't parse entities: Unsupported start tag \"x\" at byte offset 12");
        }
        if (in_array('always-timeout', $scenarios, true)) {
            $accept();

            return new MockResponse('', ['error' => 'Idle timeout reached for "https://api.telegram.org/".']);
        }
        if (1 === $n && in_array('fail-second', $scenarios, true)) {
            return $refuse('Bad Request: message is too long');
        }
        if (0 === $n) {
            if (in_array('die-in-send', $scenarios, true)) {
                exit(9);
            }
            if (in_array('die-after-send', $scenarios, true)) {
                $accept();
                exit(9);
            }
            if (in_array('timeout-after-accept', $scenarios, true)) {
                $accept();

                return new MockResponse('', ['error' => 'Idle timeout reached for "https://api.telegram.org/".']);
            }
            if (in_array('telegram-down', $scenarios, true)) {
                return $refuse('Too Many Requests');
            }
        }
    }
    $accept();

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

if (in_array('em-closed', $scenarios, true)) {
    $entityManager->close();
}

echo match ($mode) {
    'settle' => $container->get(PaymentSettlementService::class)->approveByAdmin($entityManager->find(Payment::class, (int) $id) ?? throw new RuntimeException('no such payment'), 1, null, 'concurrency_test')->outcome->value,
    'resend-job' => 'resent='.$container->get(MissingDeliveryResender::class)->run(50)['resent'],
    'attach-scheduled' => 'attached='.$container->get(InboundAttachScheduler::class)->run(500, false, 50, 0)->attached,
    default => throw new RuntimeException('unknown mode '.$mode),
};
