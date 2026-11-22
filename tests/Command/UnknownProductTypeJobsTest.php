<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnService;
use App\Payment\Application\PaymentDeliveryLog;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PM2a, fail closed (docs/design/product-modules.md 3.6, 9): every job of app:automation:run that loads services skips
 * a service whose product_type has no module (here 'x'), logs "unknown_product_type service_id=.. type=x" once per run,
 * and still processes the 'vpn' service of the same batch; the exit code is the one the 'vpn' rows give. The jobs
 * here: suspend (expiry and traffic tasks), expiry, sync, notifications and orders (resend of a missing delivery).
 * attach-inbounds, inbound-moves and claims have their own tests (UnknownProductTypeAttachTest,
 * UnknownProductTypeMoveTest, UnknownProductTypeClaimsTest).
 */
final class UnknownProductTypeJobsTest extends IntegrationTestCase
{
    private RecordingLogger $logger;
    private bool $telegramDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearSettings();
        $this->logger = self::getContainer()->get(RecordingLogger::class);
    }

    protected function tearDown(): void
    {
        $this->clearSettings();
        parent::tearDown();
    }

    public function testSuspendExpiredSkipsTheUnknownTypeAndSuspendsTheVpnService(): void
    {
        $this->setting('automation.auto_suspend_expired_enabled', '1');
        $vpn = $this->service('vpn', expiresAt: '-2 days');
        $unknown = $this->service('x', expiresAt: '-2 days');

        $tester = $this->runJob('suspend');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->statusOf($vpn));
        self::assertSame(VpnServiceStatus::ACTIVE, $this->statusOf($unknown), 'left alone');
        self::assertSame([$this->line($unknown)], $this->unknownLines());
        self::assertSame(['checked' => 1, 'suspended' => 1, 'failed' => 0, 'skipped' => 1], $this->summary('auto_suspend_expired'));
    }

    public function testSuspendTrafficExhaustedSkipsTheUnknownTypeAndSuspendsTheVpnService(): void
    {
        $this->setting('automation.auto_suspend_traffic_exhausted_enabled', '1');
        $vpn = $this->service('vpn', usedGb: 60);
        $unknown = $this->service('x', usedGb: 60);

        $tester = $this->runJob('suspend');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->statusOf($vpn));
        self::assertSame(VpnServiceStatus::ACTIVE, $this->statusOf($unknown), 'left alone');
        self::assertSame([$this->line($unknown)], $this->unknownLines());
        self::assertSame(['checked' => 1, 'suspended' => 1, 'failed' => 0, 'skipped' => 1], $this->summary('auto_suspend_traffic_exhausted'));
    }

    public function testExpirySkipsTheUnknownTypeAndExpiresTheVpnService(): void
    {
        $vpn = $this->service('vpn', expiresAt: '-2 days');
        $unknown = $this->service('x', expiresAt: '-2 days');

        $tester = $this->runJob('expiry');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(VpnServiceStatus::EXPIRED, $this->statusOf($vpn));
        self::assertSame(VpnServiceStatus::ACTIVE, $this->statusOf($unknown), 'left alone');
        self::assertNull($this->row('vpn_service', $unknown)['last_status_checked_at'], 'not even stamped as checked');
        self::assertSame([$this->line($unknown)], $this->unknownLines());
    }

    public function testSyncSkipsTheUnknownTypeAndSyncsTheVpnService(): void
    {
        $vpn = $this->service('vpn');
        $unknown = $this->service('x');

        $tester = $this->runJob('sync');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotNull($this->row('vpn_service', $vpn)['last_usage_synced_at']);
        self::assertNull($this->row('vpn_service', $unknown)['last_usage_synced_at'], 'its panel was not asked');
        self::assertNull($this->row('vpn_service', $unknown)['last_status_checked_at']);
        self::assertSame([$this->line($unknown)], $this->unknownLines());
    }

    public function testNotificationsSkipTheUnknownTypeAndWarnTheVpnCustomer(): void
    {
        // 82% of 50 GB used: one traffic_80 notice is due for each.
        $vpn = $this->service('vpn', usedGb: 41, telegramId: '7712340001');
        $unknown = $this->service('x', usedGb: 41, telegramId: '7712340002');
        $this->http->reset();

        $tester = $this->runJob('notifications');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['7712340001'], $this->chats(), 'only the vpn customer is messaged');
        self::assertSame(1, $this->noticeRows($vpn));
        self::assertSame(0, $this->noticeRows($unknown));
        self::assertSame([$this->line($unknown)], $this->unknownLines(), 'once, although three notification tasks load it');
    }

    public function testOrdersSkipTheUnknownTypesMissingDeliveryAndResendTheVpnOne(): void
    {
        $this->telegramDown = true;
        $this->http->on('/sendMessage', function (): MockResponse {
            return $this->telegramDown
                ? new MockResponse('{"ok":false,"error_code":429,"description":"Too Many Requests: retry after 5"}', ['response_headers' => ['content-type' => 'application/json']])
                : new MockResponse('{"ok":true,"result":{"message_id":1}}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $vpn = $this->sold('5550101');
        $unknown = $this->sold('5550102');
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET product_type = 'x' WHERE id = ?", [$unknown]);
        self::assertSame([PaymentDeliveryLog::PENDING, PaymentDeliveryLog::PENDING], [$this->delivery($vpn), $this->delivery($unknown)]);
        $this->telegramDown = false;
        $this->http->reset();

        $tester = $this->runJob('orders');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['5550101'], $this->chats());
        self::assertSame(PaymentDeliveryLog::SENT, $this->delivery($vpn));
        self::assertSame(PaymentDeliveryLog::PENDING, $this->delivery($unknown), 'left due, not abandoned');
        self::assertSame([$this->line($unknown)], $this->unknownLines());
    }

    public function testAWholeRunLogsTheRowOnceAndKeepsTheExitCodeOfTheOtherRows(): void
    {
        $this->setting('automation.auto_suspend_expired_enabled', '1');
        $vpn = $this->service('vpn', expiresAt: '-2 days');
        $unknown = $this->service('x', expiresAt: '-2 days');
        $this->http->reset();

        $tester = $this->runJob('all');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('failed: 0', $tester->getDisplay());
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->statusOf($vpn));
        self::assertSame(VpnServiceStatus::ACTIVE, $this->statusOf($unknown));
        self::assertNull($this->row('vpn_service', $unknown)['last_usage_synced_at']);
        self::assertSame([$this->line($unknown)], $this->unknownLines(), 'sync, expiry, suspend and notifications met it: one line');

        // The next run is a new run: one line again.
        $this->logger->records = [];
        $this->runJob('expiry');
        self::assertSame([$this->line($unknown)], $this->unknownLines());
    }

    /**
     * An active service on the test panel (50 GB, 20 days left unless $expiresAt says otherwise).
     */
    private function service(string $type, ?string $expiresAt = null, int $usedGb = 0, ?string $telegramId = null): int
    {
        $service = $this->createActiveService($this->createUser($telegramId), $this->createTestInbound())
            ->setProductType($type)
            ->setTrafficUsedGb($usedGb)
            ->setTrafficUsedBytes($usedGb * 1073741824);
        if (null !== $expiresAt) {
            $service->setExpiresAt(new \DateTimeImmutable($expiresAt));
        }
        $this->em->flush();

        return (int) $service->getId();
    }

    /** A new service sold and settled while Telegram is down: its delivery message is still due. */
    private function sold(string $telegramId): int
    {
        $plan = $this->createPlan(150000, $this->createTestInbound());
        $payment = $this->createPayment($this->createOrder($this->createUser($telegramId), $plan), null, PaymentStatus::SUBMITTED);
        self::assertSame('confirmed', self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome->value);

        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = ?', [$payment->getOrder()->getId()]);
    }

    private function runJob(string $only): CommandTester
    {
        $this->em->clear();
        $this->logger->records = [];
        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => $only]);
        $this->em->clear();

        return $tester;
    }

    private function line(int $serviceId): string
    {
        return sprintf('unknown_product_type service_id=%d type=x', $serviceId);
    }

    /** @return list<string> */
    private function unknownLines(): array
    {
        $lines = [];
        foreach ($this->logger->records as $record) {
            if (str_starts_with($record['message'], 'unknown_product_type')) {
                self::assertSame('warning', $record['level']);
                self::assertSame([], $record['context'], 'no customer data');
                $lines[] = $record['message'];
            }
        }

        return $lines;
    }

    /** @return array<string, int> */
    private function summary(string $task): array
    {
        foreach ($this->logger->records as $record) {
            if ('automation_task_completed' === $record['message'] && $task === ($record['context']['task'] ?? null)) {
                return $record['context']['summary'];
            }
        }
        self::fail('no summary for '.$task);
    }

    private function statusOf(int $serviceId): string
    {
        return (string) $this->row('vpn_service', $serviceId)['status'];
    }

    /** @return list<string> the chats sent a message, in order */
    private function chats(): array
    {
        return array_map(static function (array $request): string {
            $body = $request['options']['body'] ?? $request['options']['json'] ?? [];
            $body = is_string($body) ? (json_decode($body, true) ?: []) : $body;

            return (string) ($body['chat_id'] ?? '');
        }, $this->http->requests('/sendMessage'));
    }

    private function noticeRows(int $serviceId): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM service_notification_log WHERE service_id = ? AND type NOT LIKE 'delivery\\_%'", [$serviceId]);
    }

    private function delivery(int $serviceId): ?string
    {
        $type = $this->em->getConnection()->fetchOne("SELECT type FROM service_notification_log WHERE service_id = ? AND type LIKE 'delivery\\_%' AND type <> ?", [$serviceId, PaymentDeliveryLog::FOLLOW_UP]);

        return false === $type ? null : (string) $type;
    }

    private function setting(string $key, string $value): void
    {
        $this->em->getConnection()->executeStatement('INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, ?, NOW())', [$key, $value, 'text']);
    }

    private function clearSettings(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'automation.%'");
    }
}
