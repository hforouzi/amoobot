<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\AdminPaymentAlerter;
use App\Payment\Application\PaymentDeliveryLog;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * M2e E1 review R3, in real PHP processes on MariaDB (tests/Support/bin/delivery.php): every message of a delivery that
 * Telegram accepted is recorded at once, so a resend continues with the messages not accepted yet and never repeats an
 * accepted one; an unknown outcome (timeout) is retried (at least once), every attempt counts (a stale reclaim too), and
 * after PaymentDeliveryLog::MAX_ATTEMPTS the delivery is failed and the admins are alerted once. Each row of the review's
 * duplicate table, with the exact number of copies the customer gets.
 */
#[Group('subprocess')]
final class DeliveryAccountingTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private const DELIVERY = '✅ سرویس شما آماده شد';

    private string $stateFile;
    private string $sentFile;
    private Fake3xuiPanel $fake;
    private VpnInbound $primary;
    /** @var array<int, string> payment id => customer chat */
    private array $chats = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = sys_get_temp_dir().'/amoobot-r3-'.bin2hex(random_bytes(4)).'.json';
        $this->sentFile = $this->stateFile.'.sent';
        touch($this->sentFile);
        $this->fake = new Fake3xuiPanel();
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($panel);
        $this->primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($this->primary);
        $this->em->flush();
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach (['', '.lock', '.sent'] as $suffix) {
            @unlink($this->stateFile.$suffix);
        }
        parent::tearDown();
    }

    public function testKilledBeforeSendingIsSentOnceWhenStale(): void
    {
        $payment = $this->payment();
        self::assertSame(9, $this->exitCode('settle', $payment, 'die-in-send'));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'), 'a sender that may still run is left alone');

        $this->ageSending();
        self::assertSame('resent=1', $this->runStep('resend-job', '-'));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));

        self::assertSame(1, $this->copies($payment));
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
    }

    public function testKilledAfterTelegramAcceptedButBeforeRecordingGivesTwoCopiesAtMost(): void
    {
        $payment = $this->payment();
        self::assertSame(9, $this->exitCode('settle', $payment, 'die-after-send'));
        self::assertSame(1, $this->copies($payment));

        $this->ageSending();
        $this->runStep('resend-job', '-');
        $this->ageSending();
        $this->runStep('resend-job', '-');

        self::assertSame(2, $this->copies($payment), 'at least once: the unrecorded message is sent again, once');
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
        self::assertSame(0, $this->alerts());
    }

    public function testATimeoutAfterAcceptanceOnceGivesTwoCopies(): void
    {
        $payment = $this->payment();
        self::assertSame('confirmed', $this->runStep('settle', $payment, 'timeout-after-accept'));
        self::assertSame(PaymentDeliveryLog::PENDING, $this->state($payment), 'an unknown outcome is not "sent"');

        self::assertSame('resent=1', $this->runStep('resend-job', '-'));
        self::assertSame('resent=0', $this->runStep('resend-job', '-'));

        self::assertSame(2, $this->copies($payment));
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
    }

    public function testATimeoutAfterAcceptanceOnEveryTryStopsAtTheAttemptCapAndAlertsOnce(): void
    {
        $payment = $this->payment();
        self::assertSame('confirmed', $this->runStep('settle', $payment, 'always-timeout'));
        for ($i = 0; $i < 4; ++$i) {
            $this->runStep('resend-job', '-', 'always-timeout');
        }
        $this->runStep('settle', $payment, 'always-timeout');

        self::assertSame(PaymentDeliveryLog::MAX_ATTEMPTS, $this->copies($payment), 'one copy per attempt, never more than the cap');
        self::assertSame(PaymentDeliveryLog::FAILED, $this->state($payment));
        self::assertSame(1, $this->alerts(), 'the admins are alerted once');
    }

    public function testAClosedEntityManagerInTheJobStillCountsTheAcceptedMessage(): void
    {
        $payment = $this->payment();
        self::assertSame('confirmed', $this->runStep('settle', $payment, 'telegram-down'));
        self::assertSame(0, $this->copies($payment));

        $this->runStep('resend-job', '-', 'em-closed');
        $this->runStep('resend-job', '-', 'em-closed');

        self::assertSame(1, $this->copies($payment));
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
    }

    public function testRepeatedKillsOnStaleReclaimAreBoundedByTheAttemptCap(): void
    {
        $payment = $this->payment();
        self::assertSame(9, $this->exitCode('settle', $payment, 'die-after-send'));
        for ($i = 0; $i < 4; ++$i) {
            $this->ageSending();
            $this->exitCode('resend-job', '-', 'die-after-send');
        }
        $this->ageSending();
        $this->runStep('resend-job', '-');
        $this->runStep('resend-job', '-');

        self::assertSame(PaymentDeliveryLog::MAX_ATTEMPTS, $this->copies($payment));
        self::assertSame(PaymentDeliveryLog::FAILED, $this->state($payment));
        self::assertSame(1, $this->alerts());
    }

    public function testTwoSettlementsAndAJobAtOnceThenThreeJobsOnStaleRowsSendEachDeliveryOnce(): void
    {
        $fresh = $this->payment();
        $processes = [$this->start('settle', $fresh), $this->start('settle', $fresh), $this->start('resend-job', '-')];
        $stale = [];
        foreach ($processes as $process) {
            $process->wait();
        }
        for ($i = 0; $i < 3; ++$i) {
            $stale[] = $payment = $this->payment();
            self::assertSame(9, $this->exitCode('settle', $payment, 'die-in-send'));
        }
        $this->ageSending();
        $jobs = [$this->start('resend-job', '-'), $this->start('resend-job', '-'), $this->start('resend-job', '-')];
        foreach ($jobs as $job) {
            $job->wait();
            self::assertSubprocessClean($job, 'job');
        }

        foreach ([$fresh, ...$stale] as $payment) {
            self::assertSame(1, $this->copies($payment));
            self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
        }
    }

    public function testAFailedMessageDoesNotStopTheNextOnesAndIsTheOnlyOneResent(): void
    {
        $this->longLinks();
        $payment = $this->payment();
        self::assertSame('confirmed', $this->runStep('settle', $payment, 'fail-second'));
        $first = $this->texts($payment);
        self::assertCount(2, $first, 'messages 1 and 3 went out although 2 was refused');
        self::assertSame(PaymentDeliveryLog::PENDING, $this->state($payment), 'not all accepted: not sent yet');

        self::assertSame('resent=1', $this->runStep('resend-job', '-'));

        $texts = $this->texts($payment);
        self::assertCount(3, $texts);
        self::assertCount(3, array_unique($texts), 'each message exactly once');
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
    }

    public function testAnHtmlParseErrorFallsBackToPlainTextWithTheLinks(): void
    {
        $payment = $this->payment();
        self::assertSame('confirmed', $this->runStep('settle', $payment, 'parse-error'));

        $messages = $this->messages($payment);
        self::assertCount(1, $messages);
        self::assertFalse($messages[0]['html']);
        self::assertStringStartsWith(self::DELIVERY, $messages[0]['text']);
        self::assertStringNotContainsString('<code>', $messages[0]['text']);
        self::assertMatchesRegularExpression('#vless://[0-9a-f-]+@#', $messages[0]['text'], 'the links are in the plain text');
        self::assertSame(PaymentDeliveryLog::SENT, $this->state($payment));
    }

    /** Three hosts with very long remarks on the primary: the delivery is three messages (one link each). */
    private function longLinks(): void
    {
        $this->fake->loadState();
        $this->fake->addHostGroup(str_repeat('a', 1500), [1], ['edge1.example.com', 'edge2.example.com', 'edge3.example.com']);
        $this->fake->saveState();
    }

    private function payment(): Payment
    {
        $user = $this->createUser();
        $payment = $this->createPayment($this->createOrder($user, $this->createPlan(150000, $this->primary)), null, PaymentStatus::SUBMITTED);
        $this->chats[(int) $payment->getId()] = (string) $this->em->getRepository(TelegramAccount::class)->findOneBy(['user' => $user])?->getTelegramId();

        return $payment;
    }

    private function start(string $mode, Payment|string $id, string $scenarios = ''): Process
    {
        $id = $id instanceof Payment ? (string) $id->getId() : $id;
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/delivery.php', $mode, $id, $this->stateFile, '0', $this->sentFile, $scenarios], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
        $process->start();

        return $process;
    }

    private function runStep(string $mode, Payment|string $id, string $scenarios = ''): string
    {
        $process = $this->start($mode, $id, $scenarios);
        $process->wait();

        return self::assertSubprocessClean($process, $mode);
    }

    private function exitCode(string $mode, Payment|string $id, string $scenarios): ?int
    {
        $process = $this->start($mode, $id, $scenarios);
        $process->wait();

        return $process->getExitCode();
    }

    /** A sender that died (or still runs) for longer than PaymentDeliveryLog::STALE_SENDING. */
    private function ageSending(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE service_notification_log SET sent_at = DATE_SUB(sent_at, INTERVAL 6 MINUTE) WHERE type = ?', [PaymentDeliveryLog::SENDING]);
    }

    /** @return list<array{text: string, html: bool}> what Telegram accepted for this payment's customer, in order */
    private function messages(Payment $payment): array
    {
        $chat = $this->chats[(int) $payment->getId()];
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file($this->sentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        return array_values(array_map(static fn (array $l): array => ['text' => (string) $l['text'], 'html' => (bool) ($l['html'] ?? false)], array_filter($lines, static fn (array $l): bool => $l['chat_id'] === $chat)));
    }

    /** @return list<string> */
    private function texts(Payment $payment): array
    {
        return array_column($this->messages($payment), 'text');
    }

    /** Copies of the delivery's first message the customer got. */
    private function copies(Payment $payment): int
    {
        return count(array_filter($this->texts($payment), static fn (string $t): bool => str_starts_with($t, self::DELIVERY)));
    }

    private function alerts(): int
    {
        $lines = array_map(static fn (string $l): array => json_decode($l, true), file($this->sentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        return count(array_filter($lines, static fn (array $l): bool => str_starts_with((string) $l['text'], AdminPaymentAlerter::DELIVERY_FAILED_TITLE)));
    }

    private function state(Payment $payment): ?string
    {
        $type = $this->em->getConnection()->fetchOne('SELECT type FROM service_notification_log WHERE key_name = ? AND type IN (?, ?, ?, ?, ?)', [PaymentDeliveryLog::key((int) $payment->getOrder()->getId()), PaymentDeliveryLog::PENDING, PaymentDeliveryLog::SENDING, PaymentDeliveryLog::SENT, PaymentDeliveryLog::FAILED, PaymentDeliveryLog::ABANDONED]);

        return false === $type ? null : (string) $type;
    }
}
