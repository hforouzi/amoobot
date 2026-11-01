<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\PaymentDeliveryLog;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * M2e E1, in real PHP processes on MariaDB against one fake 3x-ui (tests/Support/bin/delivery.php): the customer's
 * delivery message ("service ready") is sent before the attach pass of the additional inbounds, recorded in
 * PaymentDeliveryLog, sent again by a retried settlement or the scheduled job when it never went out, not twice in
 * these cases (the exact bounds: DeliveryAccountingTest); one short follow-up when the pass attached servers. Every Telegram call of every process is counted.
 */
#[Group('subprocess')]
final class DeliveryMessageTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private const DELIVERY = '✅ سرویس شما آماده شد';
    private const FOLLOW_UP = '➕';

    private string $stateFile;
    private string $sentFile;
    private VpnInbound $primary;
    /** @var list<VpnInbound> */
    private array $additional = [];
    private string $customerChat = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = sys_get_temp_dir().'/amoobot-m2e-'.bin2hex(random_bytes(4)).'.json';
        $this->sentFile = $this->stateFile.'.sent';
        touch($this->sentFile);
        $fake = new Fake3xuiPanel();
        $remoteIds = [2, $fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083])];
        $fake->stateFile = $this->stateFile;
        $fake->saveState();
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($panel);
        $this->primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($this->primary);
        foreach ($remoteIds as $remoteId) {
            $this->additional[] = $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remoteId)->setTitle('extra '.$remoteId)->setProtocol('vmess');
            $this->em->persist($inbound);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => MultiInboundSettings::ENABLED, 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach (['', '.lock', '.sent'] as $suffix) {
            @unlink($this->stateFile.$suffix);
        }
        parent::tearDown();
    }

    public function testKilledDuringThePassTheCustomerAlreadyHasTheDeliveryAndARetryDoesNotSendItAgain(): void
    {
        $payment = $this->payment(true);
        $settle = $this->start('settle', (int) $payment->getId(), 1500, 'primary-only');
        // The pass has claimed the rows: the commit and the delivery are behind it.
        $this->waitFor(fn (): bool => ServiceInboundAttacher::ATTACHING === $this->db('SELECT last_error FROM vpn_service_inbound WHERE service_id = (SELECT id FROM vpn_service WHERE order_id = ?) LIMIT 1', [$payment->getOrder()->getId()]));
        $settle->signal(9);
        $settle->wait();

        self::assertSame(PaymentStatus::CONFIRMED, $this->db('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(1, $this->sends(self::DELIVERY), 'the delivery was sent before the pass');

        self::assertSame('already_processed', $this->runStep('settle', (int) $payment->getId()));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));
        self::assertSame(1, $this->sends(self::DELIVERY), 'exactly one delivery message after the retry and the job');
        self::assertSame(0, $this->sends(self::FOLLOW_UP), 'no follow-up from a pass that was killed');
    }

    public function testTwoSettlementsAtOnceSendOneDeliveryAndOneFollowUp(): void
    {
        $payment = $this->payment(true);
        $a = $this->start('settle', (int) $payment->getId(), 600, 'primary-only');
        $b = $this->start('settle', (int) $payment->getId(), 600, 'primary-only');
        $a->wait();
        $b->wait();
        $outcomes = [self::assertSubprocessClean($a, 'settle a'), self::assertSubprocessClean($b, 'settle b')];
        sort($outcomes);

        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        self::assertSame(1, $this->sends(self::DELIVERY));
        self::assertSame(1, $this->sends(self::FOLLOW_UP), 'the pass attached both: one follow-up');
        self::assertStringContainsString('2 سرور دیگر', $this->texts(self::FOLLOW_UP)[0]);
        self::assertLessThan($this->line(self::FOLLOW_UP), $this->line(self::DELIVERY), 'the delivery comes first');
    }

    public function testNoFollowUpWhenThePassAttachedNothing(): void
    {
        $payment = $this->payment(true);
        self::assertSame('confirmed', $this->runStep('settle', (int) $payment->getId(), 0, 'primary-only,attach-refused'));

        self::assertSame(1, $this->sends(self::DELIVERY));
        self::assertSame(0, $this->sends(self::FOLLOW_UP));
        self::assertSame(['failed', 'failed'], array_column($this->rows($payment), 'status'));
    }

    public function testADeliveryThatFailedIsSentOnceByTheRetriedSettlement(): void
    {
        $payment = $this->payment(true);
        self::assertSame('confirmed', $this->runStep('settle', (int) $payment->getId(), 0, 'primary-only,telegram-down'));
        self::assertSame(0, $this->sends(self::DELIVERY), 'Telegram refused it');
        self::assertSame(0, $this->sends(self::FOLLOW_UP), 'no follow-up before the delivery');
        self::assertSame(PaymentDeliveryLog::PENDING, $this->deliveryState($payment));

        // A retried settlement and the scheduled job at once: one of them sends it.
        $retry = $this->start('settle', (int) $payment->getId(), 0);
        $job = $this->start('resend-job', '-', 0);
        $retry->wait();
        $job->wait();
        self::assertSame('already_processed', self::assertSubprocessClean($retry, 'retry'));
        self::assertSubprocessClean($job, 'job');
        self::assertSame(1, $this->sends(self::DELIVERY));
        self::assertSame(PaymentDeliveryLog::SENT, $this->deliveryState($payment));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));
        self::assertSame(1, $this->sends(self::DELIVERY));
    }

    public function testAProcessThatDiedWhileSendingIsResentOnceByTheScheduledJobWhenStale(): void
    {
        $payment = $this->payment(true);
        $settle = $this->start('settle', (int) $payment->getId(), 0, 'primary-only,die-in-send');
        $settle->wait();
        self::assertSame(9, $settle->getExitCode());
        self::assertSame(PaymentStatus::CONFIRMED, $this->db('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(PaymentDeliveryLog::SENDING, $this->deliveryState($payment));

        // A sender that may still be running is left alone.
        self::assertSame('already_processed', $this->runStep('settle', (int) $payment->getId()));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));
        self::assertSame(0, $this->sends(self::DELIVERY));

        $this->em->getConnection()->executeStatement('UPDATE service_notification_log SET sent_at = DATE_SUB(sent_at, INTERVAL 6 MINUTE) WHERE type = ?', [PaymentDeliveryLog::SENDING]);
        self::assertSame('resent=1', $this->runStep('resend-job', '-'));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));
        self::assertSame(1, $this->sends(self::DELIVERY));
        self::assertSame(PaymentDeliveryLog::SENT, $this->deliveryState($payment));
    }

    public function testASingleInboundSaleSendsTheSameDeliveryAndNoFollowUp(): void
    {
        $payment = $this->payment(false);
        self::assertSame('confirmed', $this->runStep('settle', (int) $payment->getId()));

        self::assertSame(1, $this->sends(self::DELIVERY));
        self::assertSame(0, $this->sends(self::FOLLOW_UP));
        self::assertSame(PaymentDeliveryLog::SENT, $this->deliveryState($payment));
        self::assertSame([], $this->rows($payment));
    }

    private function payment(bool $multi): Payment
    {
        $plan = $this->createPlan(150000, $this->primary);
        if ($multi) {
            $plan->setAdditionalInbounds($this->additional);
        }
        $this->em->flush();
        $user = $this->createUser();
        $this->customerChat = (string) $this->em->getRepository(TelegramAccount::class)->findOneBy(['user' => $user])?->getTelegramId();

        return $this->createPayment($this->createOrder($user, $plan), null, PaymentStatus::SUBMITTED);
    }

    private function start(string $mode, int|string $id, int $delayMs = 0, string $scenarios = ''): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/delivery.php', $mode, (string) $id, $this->stateFile, (string) $delayMs, $this->sentFile, $scenarios], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $process->start();

        return $process;
    }

    private function runStep(string $mode, int|string $id, int $delayMs = 0, string $scenarios = ''): string
    {
        $process = $this->start($mode, $id, $delayMs, $scenarios);
        $process->wait();

        return self::assertSubprocessClean($process, $mode);
    }

    private function waitFor(\Closure $condition): void
    {
        $until = microtime(true) + 60;
        while (!$condition()) {
            self::assertLessThan($until, microtime(true), 'timed out');
            usleep(50000);
        }
    }

    /** @return list<array{chat_id: string, text: string}> the customer's messages, in order */
    private function customerMessages(): array
    {
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file($this->sentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        return array_values(array_filter($lines, fn (array $l): bool => $l['chat_id'] === $this->customerChat));
    }

    /** @return list<string> */
    private function texts(string $startsWith): array
    {
        return array_values(array_filter(array_column($this->customerMessages(), 'text'), static fn (string $t): bool => str_starts_with($t, $startsWith)));
    }

    private function sends(string $startsWith): int
    {
        return count($this->texts($startsWith));
    }

    private function line(string $startsWith): int
    {
        foreach ($this->customerMessages() as $i => $message) {
            if (str_starts_with($message['text'], $startsWith)) {
                return $i;
            }
        }

        return -1;
    }

    private function deliveryState(Payment $payment): ?string
    {
        $type = $this->db('SELECT type FROM service_notification_log WHERE key_name = ? AND type LIKE ?', [PaymentDeliveryLog::key((int) $payment->getOrder()->getId()), 'delivery\_%']);

        return false === $type ? null : (string) $type;
    }

    /** @return list<array<string, mixed>> */
    private function rows(Payment $payment): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT status FROM vpn_service_inbound WHERE service_id = (SELECT id FROM vpn_service WHERE order_id = ?) ORDER BY id', [$payment->getOrder()->getId()]);
    }

    /** @param list<mixed> $params */
    private function db(string $sql, array $params = []): mixed
    {
        return $this->em->getConnection()->fetchOne($sql, $params);
    }
}

