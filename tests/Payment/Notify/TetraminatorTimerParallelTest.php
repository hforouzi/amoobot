<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Setting;
use App\Payment\Application\Reconcile\TetraminatorPollSchedule;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use App\Tests\Support\TetraminatorNotifyFixtures;
use App\Wallet\Application\WalletSettings;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The timer and a notify request for one payment, in two real processes (design.md decision 10): settle() and
 * expireUnpaid() lock order and payment, so whichever locks first wins and the other does nothing, or parks late money
 * for review. Never two confirmations, never money ignored.
 */
final class TetraminatorTimerParallelTest extends IntegrationTestCase
{
    use ParallelStart;
    use SubprocessAssertions;
    use TetraminatorNotifyFixtures;

    private string $panelState = '';
    private int $now = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->panelState = sys_get_temp_dir().'/tetraminator-timer-race-panel-'.bin2hex(random_bytes(6)).'.json';
        $this->installTetraminator();
        $this->now = TetraminatorPollSchedule::tick(new \DateTimeImmutable());
    }

    protected function tearDown(): void
    {
        @unlink($this->panelState);
        parent::tearDown();
    }

    /** A payment whose invoice is $age seconds old at the timer's run. */
    private function aged(int $age, ?Order $order = null): Payment
    {
        $payment = $this->tetraminatorPayment($order);
        $payment->setExpiresAt((new \DateTimeImmutable('@'.($this->now + TetraminatorPollSchedule::INVOICE_LIFETIME_SECONDS - $age)))->setTimezone(new \DateTimeZone(date_default_timezone_get())));
        $this->em->flush();

        return $payment;
    }

    /**
     * @param list<array<string, mixed>> $answers taken in order of the inquiries; the timer starts first, notify $notifyDelay later
     *
     * @return array{timer: string, notify: string, messages: int, status: mixed, panelClients: int}
     */
    private function race(Payment $payment, array $answers, float $notifyDelay = 0.0): array
    {
        $this->tetraminatorAnswers($answers);
        $chatId = (string) $payment->getOrder()->getUser()->getTelegramAccount()?->getTelegramId();
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $env = ['APP_ENV' => 'test', 'TEST_PANEL_STATE_FILE' => $this->panelState, 'TEST_PANEL_SLEEP_AFTER_CREATE_MS' => '1500'];
        $processes = [
            'timer' => new Process([$php, 'tests/Support/bin/tetraminator-timer.php', (string) $this->now, $chatId, $this->startAt()], dirname(__DIR__, 3), $env, null, 240),
            'notify' => new Process([$php, 'tests/Support/bin/tetraminator-notify.php', (string) $payment->getId(), self::TETRA_TOKEN, $chatId, $this->startAt($notifyDelay)], dirname(__DIR__, 3), $env, null, 240),
        ];
        foreach ($processes as $process) {
            $process->start();
        }
        $this->releaseStart(array_values($processes));

        $outputs = [];
        foreach ($processes as $side => $process) {
            $process->wait();
            $outputs[$side] = self::assertSubprocessClean($process, 'tetraminator timer race, '.$side);
        }
        $this->em->clear();
        $state = is_file($this->panelState) ? json_decode((string) file_get_contents($this->panelState), true) : [];
        $timerParts = explode(':', $outputs['timer']);

        return [
            'timer' => $outputs['timer'],
            'notify' => $outputs['notify'],
            'messages' => (int) end($timerParts) + (int) substr($outputs['notify'], (int) strpos($outputs['notify'], ':') + 1),
            'status' => $this->row('payment', (int) $payment->getId())['status'],
            'panelClients' => count($state),
        ];
    }

    public function testTimerAndNotifyOnOnePaidPaymentConfirmProvisionAndDeliverOnce(): void
    {
        $payment = $this->aged(4 * 60);

        $result = $this->race($payment, [self::tetraPaid(sleepMs: 1000), self::tetraPaid(sleepMs: 1000)]);

        self::assertSame(PaymentStatus::CONFIRMED, $result['status'], $result['timer'].' / '.$result['notify']);
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
        self::assertSame(1, $result['panelClients']);
        self::assertSame(1, $result['messages'], 'one delivery message');
        self::assertStringStartsWith('page:', $result['notify']);
    }

    public function testTimerAndNotifyOnOnePaidTopUpCreditOnce(): void
    {
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true')->setType('boolean'));
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(self::TETRA_AMOUNT)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->aged(4 * 60, $order);

        $result = $this->race($payment, [self::tetraPaid(sleepMs: 1000), self::tetraPaid(sleepMs: 1000)]);

        self::assertSame(PaymentStatus::CONFIRMED, $result['status']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
        self::assertSame(self::TETRA_AMOUNT, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$order->getUser()->getId()]));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function expiryAgainstSettle(): iterable
    {
        // The timer's "not paid" arrives after the notify's "paid": notify settles first, the expiry finds it confirmed.
        yield 'settle locks first' => [1500, 0];
        // The timer expires first: the paid answer reaches a rejected-unpaid payment and parks it for review.
        yield 'expiry locks first' => [0, 1500];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expiryAgainstSettle')]
    public function testTimerExpiryAgainstNotifySettleEndsConfirmedOrInReviewNeverIgnored(int $timerAnswerMs, int $notifyAnswerMs): void
    {
        $payment = $this->aged(70 * 60);

        // The timer asks first (notify starts 0.3 s later), so it takes the first answer.
        $result = $this->race($payment, [self::tetraUnpaid() + ['sleep_ms' => $timerAnswerMs], self::tetraPaid(sleepMs: $notifyAnswerMs)], 0.3);

        self::assertContains($result['status'], [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW], $result['timer'].' / '.$result['notify']);
        $services = $this->countServicesForOrder($payment->getOrder());
        self::assertSame(PaymentStatus::CONFIRMED === $result['status'] ? 1 : 0, $services);
        self::assertCount(2, $this->tetraminatorCalls());
    }
}
