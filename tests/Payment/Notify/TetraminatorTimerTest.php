<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Setting;
use App\Payment\Application\Reconcile\TetraminatorPollSchedule;
use App\Payment\Application\Reconcile\TetraminatorReconciler;
use App\Payment\Application\Reconcile\TetraminatorRunSummary;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TetraminatorNotifyFixtures;
use App\Wallet\Application\WalletSettings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The Tetraminator timer (design.md decision 4): TetraminatorReconciler::runDue() at a $now the test controls, and
 * app:automation:run --only=tetraminator with its exit codes.
 */
final class TetraminatorTimerTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;
    use TetraminatorNotifyFixtures;

    private const MIN = 60;
    private const HOUR = 3600;

    private \DateTimeImmutable $now;
    private ?PaymentGateway $gateway = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTetraminator();
        // A tick, in the default time zone: the ages below are exact.
        $this->now = (new \DateTimeImmutable('@'.TetraminatorPollSchedule::tick(new \DateTimeImmutable())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function reconciler(): TetraminatorReconciler
    {
        return self::getContainer()->get(TetraminatorReconciler::class);
    }

    private function runTimer(?\DateTimeImmutable $at = null, ?float $budget = null): TetraminatorRunSummary
    {
        $summary = $this->reconciler()->runDue($at ?? $this->now, false, $budget);
        $this->em->clear();

        return $summary;
    }

    /** A Tetraminator payment whose invoice is $age seconds old at $this->now. */
    private function aged(int $age, ?Order $order = null, string $payId = self::TETRA_PAY_ID, string $status = PaymentStatus::PENDING, ?string $source = null): Payment
    {
        $this->gateway ??= $this->tetraminatorGateway();
        $payment = $this->tetraminatorPayment($order, $this->gateway)
            ->setGatewayTransactionId($payId)
            ->setExpiresAt($this->now->modify(sprintf('%+d seconds', TetraminatorPollSchedule::INVOICE_LIFETIME_SECONDS - $age)))
            ->setStatus($status)
            ->setConfirmationSource($source);
        $this->em->flush();

        return $payment;
    }

    private function newOrder(): Order
    {
        return $this->createOrder($this->createUser(), $this->createPlan(self::TETRA_AMOUNT, $this->createTestInbound()));
    }

    /** @return array<string, mixed> */
    private function paymentRow(Payment $payment): array
    {
        return $this->row('payment', (int) $payment->getId());
    }

    /** @return array<string, mixed> */
    private static function transport(): array
    {
        return ['transport' => 'Connection reset by peer'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function command(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => 'tetraminator'] + $options);
        $this->em->clear();

        return $tester;
    }

    /** @return list<string> */
    private function logged(string $level): array
    {
        $logger = self::getContainer()->get(RecordingLogger::class);

        return array_values(array_map(static fn (array $r): string => $r['message'], array_filter($logger->records, static fn (array $r): bool => $level === $r['level'])));
    }

    public function testAgeIsCountedFromTheInvoiceNotFromTheRow(): void
    {
        $payment = $this->aged(119);
        // The row is a day old (a reused row gets a new invoice): that does not make the invoice due.
        $this->em->getConnection()->executeStatement('UPDATE payment SET created_at = ? WHERE id = ?', [$this->now->modify('-1 day')->format('Y-m-d H:i:s'), $payment->getId()]);
        $this->tetraminatorAnswers([self::tetraPaid()]);

        self::assertSame(0, $this->runTimer()->due);
        self::assertSame(0, $this->runTimer($this->now->modify('+119 seconds'))->due, 'a run that starts late belongs to the same tick');
        self::assertSame([], $this->tetraminatorCalls());
        self::assertSame(1, $this->runTimer($this->now->modify('+120 seconds'))->asked, 'the next tick: 2 minutes old');
    }

    public function testPaidFoundByTheTimerIsConfirmedOnceWithoutAWebhook(): void
    {
        $payment = $this->aged(4 * self::MIN);
        $this->tetraminatorAnswers([self::tetraPaid(), self::tetraPaid()]);

        $first = $this->runTimer();
        $second = $this->runTimer($this->now->modify('+2 minutes'));

        self::assertSame(1, $first->settled);
        self::assertSame(0, $second->candidates, 'a confirmed payment is no candidate');
        self::assertCount(1, $this->tetraminatorCalls());
        $row = $this->paymentRow($payment);
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(TetraminatorReconciler::SOURCE_TIMER, $row['confirmation_source']);
        self::assertSame(1, $this->countServicesForOrder($payment->getOrder()));
    }

    public function testAWellFormedUnpaidAnswerAt70MinutesExpiresItAndTheOrderStaysOpen(): void
    {
        $payment = $this->aged(70 * self::MIN);
        $this->tetraminatorAnswers([self::tetraUnpaid()]);

        $summary = $this->runTimer();

        self::assertSame(1, $summary->expired);
        $row = $this->paymentRow($payment);
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_GATEWAY_INVOICE_EXPIRY, $row['confirmation_source']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $payment->getOrder()->getId())['status'], 'the customer can start again');
        $reloaded = $this->em->find(Payment::class, $payment->getId());
        self::assertTrue($reloaded?->isRejectedUnpaid(), 'late money still reaches review');
        self::assertSame([], $this->logged('error'));
    }

    public function testAnUnpaidAnswerInsideTheInvoiceWindowExpiresNothing(): void
    {
        $payment = $this->aged(24 * self::MIN);
        $this->tetraminatorAnswers([self::tetraUnpaid()]);

        $summary = $this->runTimer();

        self::assertSame([1, 1, 0], [$summary->asked, $summary->notPaid, $summary->expired]);
        self::assertSame(PaymentStatus::PENDING, $this->paymentRow($payment)['status']);
    }

    public function testErrorAnswersNeverExpireBefore24HoursAndExpireAt24HoursWithAnError(): void
    {
        $payment = $this->aged(70 * self::MIN);
        $this->tetraminatorAnswers([self::transport(), ['status' => 500, 'body' => []], self::transport()]);

        $at70 = $this->runTimer();
        // 23:50 old: still the error tier.
        $at2350 = $this->runTimer($this->now->modify(sprintf('+%d seconds', 86400 - 600 - 4200)));
        self::assertSame(PaymentStatus::PENDING, $this->paymentRow($payment)['status']);
        $at24h = $this->runTimer($this->now->modify(sprintf('+%d seconds', 86400 - 4200)));

        self::assertSame([1, 0], [$at70->verifyFailed, $at70->expired]);
        self::assertSame([1, 0], [$at2350->verifyFailed, $at2350->expired]);
        self::assertSame([1, 1, 1], [$at24h->verifyFailed, $at24h->expired, $at24h->expiredUnanswered]);
        self::assertSame(PaymentStatus::REJECTED, $this->paymentRow($payment)['status']);
        self::assertContains('Tetraminator timer: expired after 24 hours without a well-formed answer; compare with the Tetraminator panel.', $this->logged('error'));
    }

    public function testARejectedUnpaidPaymentPaidAtThe6HourSweepGoesToReview(): void
    {
        $order = $this->newOrder();
        $order->setStatus(OrderStatus::CANCELLED);
        $payment = $this->aged(5 * self::HOUR, $order, status: PaymentStatus::REJECTED, source: Payment::SOURCE_CUSTOMER_CANCEL);
        $this->tetraminatorAnswers([self::tetraPaid()]);

        self::assertSame(0, $this->runTimer()->due, '5 h: between the sweeps');
        $sweep = $this->runTimer($this->now->modify('+1 hour'));

        self::assertSame([1, 1], [$sweep->asked, $sweep->review]);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->paymentRow($payment)['status']);
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }

    public function testAnAdminRejectionIsNeverSwept(): void
    {
        $this->aged(6 * self::HOUR, status: PaymentStatus::REJECTED, source: 'admin_panel');
        $this->tetraminatorAnswers([self::tetraPaid()]);

        self::assertSame(0, $this->runTimer()->candidates);
        self::assertSame([], $this->tetraminatorCalls());
    }

    public function testAWalletTopUpPaidByTheTimerHasOneLedgerRow(): void
    {
        $this->em->persist((new Setting())->setKeyName(WalletSettings::ENABLED)->setValue('true')->setType('boolean'));
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(self::TETRA_AMOUNT)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->aged(6 * self::MIN, $order);
        $this->tetraminatorAnswers([self::tetraPaid(), self::tetraPaid()]);

        $this->runTimer();
        $this->runTimer($this->now->modify('+2 minutes'));

        self::assertSame(PaymentStatus::CONFIRMED, $this->paymentRow($payment)['status']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
    }

    public function testOpenInTheWindowFirstThenOverdueThenLateSweeps(): void
    {
        $this->aged(6 * self::HOUR, $this->newOrder(), 'TPAY-LATE', PaymentStatus::REJECTED, Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED);
        $this->aged(70 * self::MIN, $this->newOrder(), 'TPAY-OVERDUE');
        $this->aged(4 * self::MIN, $this->newOrder(), 'TPAY-NEW');
        $this->aged(24 * self::MIN, $this->newOrder(), 'TPAY-OLDER');
        $this->tetraminatorAnswers(array_fill(0, 4, self::tetraUnpaid()));

        self::assertSame(4, $this->runTimer()->asked);

        $order = array_map(static fn (array $call): string => substr($call['url'], (int) strrpos($call['url'], '/') + 1), $this->tetraminatorCalls());
        self::assertSame(['TPAY-OLDER', 'TPAY-NEW', 'TPAY-OVERDUE', 'TPAY-LATE'], $order);
    }

    public function testAtMost40InquiriesARunAndTheOverflowIsAWarning(): void
    {
        $order = $this->newOrder();
        $plan = $order->getPlan();
        for ($i = 0; $i < 45; ++$i) {
            $this->aged(5 * self::MIN, 0 === $i ? $order : $this->createOrder($order->getUser(), $plan), sprintf('TPAY-%02d', $i));
        }
        $this->tetraminatorAnswers(array_fill(0, 45, self::tetraUnpaid()));

        $summary = $this->runTimer();

        self::assertSame([45, 40, 5, TetraminatorRunSummary::STOP_LIMIT], [$summary->due, $summary->asked, $summary->leftOver, $summary->stoppedBy]);
        self::assertCount(40, $this->tetraminatorCalls());
        self::assertContains('Tetraminator timer: stopped before the end of the due list; the rest waits for the next run.', $this->logged('warning'));
    }

    public function testTheTimeBudgetStopsTheRun(): void
    {
        foreach (['TPAY-A', 'TPAY-B', 'TPAY-C'] as $payId) {
            $this->aged(5 * self::MIN, $this->newOrder(), $payId);
        }
        $slow = self::tetraUnpaid() + ['sleep_ms' => 400];
        $this->tetraminatorAnswers([$slow, $slow, $slow]);

        $summary = $this->runTimer(budget: 0.3);

        self::assertSame([1, 2, TetraminatorRunSummary::STOP_TIME_BUDGET], [$summary->asked, $summary->leftOver, $summary->stoppedBy]);
    }

    public function testThreeTransportErrorsInARowStopTheRunAndExit0WithAWarning(): void
    {
        foreach (range(1, 5) as $i) {
            $this->aged(5 * self::MIN, $this->newOrder(), 'TPAY-'.$i);
        }
        $this->tetraminatorAnswers([self::transport(), ['status' => 502, 'body' => []], self::transport(), self::tetraPaid(), self::tetraPaid()]);

        $tester = $this->command();

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(3, $this->tetraminatorCalls());
        self::assertConsoleContains('3 inquiries failed', $tester->getDisplay());
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM payment WHERE status = 'pending'"));
    }

    public function testAnErrorBetweenSuccessesDoesNotTripTheBreaker(): void
    {
        foreach (range(1, 5) as $i) {
            $this->aged(5 * self::MIN, $this->newOrder(), 'TPAY-'.$i);
        }
        $this->tetraminatorAnswers([self::transport(), self::transport(), self::tetraUnpaid(), self::transport(), self::transport()]);

        $summary = $this->runTimer();

        self::assertSame([5, null], [$summary->asked, $summary->stoppedBy]);
    }

    public function testThe401StopsTheRunAndExits1(): void
    {
        foreach (range(1, 3) as $i) {
            $this->aged(5 * self::MIN, $this->newOrder(), 'TPAY-'.$i);
        }
        $this->tetraminatorAnswers([['status' => 401, 'body' => []], self::tetraPaid(), self::tetraPaid()]);

        $tester = $this->command();

        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(1, $this->tetraminatorCalls(), 'stopped at the first refusal');
        self::assertConsoleContains('refused the API key or the store', $tester->getDisplay());
    }

    public function testNothingToDoWithoutThePluginOrPaymentsExits0(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");

        $tester = $this->command();

        self::assertSame(0, $tester->getStatusCode());
        self::assertConsoleContains('due: 0 of 0', $tester->getDisplay());
    }

    public function testADriverThatIsGoneWhilePaymentsAreOpenExits1(): void
    {
        $this->aged(5 * self::MIN);
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");

        $tester = $this->command();

        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains('driver is unavailable', $tester->getDisplay());
        self::assertContains('Tetraminator timer: the gateway driver is unavailable while it has open payments; an admin must check the plugin.', $this->logged('error'));
    }

    public function testADriverThatIsGoneWithOnlyRejectedPaymentsExits0(): void
    {
        $this->aged(6 * self::HOUR, status: PaymentStatus::REJECTED, source: Payment::SOURCE_CUSTOMER_CANCEL);
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");

        self::assertSame(0, $this->command()->getStatusCode());
    }

    public function testAnOpenPaymentWithNoWellFormedAnswerAnHourPastItsInvoiceExits1(): void
    {
        $this->aged(2 * self::HOUR + 30);
        $this->tetraminatorAnswers([self::transport(), self::transport()]);

        $tester = $this->command();

        self::assertSame(1, $tester->getStatusCode());
        self::assertConsoleContains('1 open payments have no well-formed answer 1 h past their invoice', $tester->getDisplay());
    }

    public function testOnlyTetraminatorRunsIt(): void
    {
        $this->aged(5 * self::MIN);
        $this->tetraminatorAnswers([['status' => 401, 'body' => []]]);
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code = 'tetraminator'");

        foreach (['claims', 'all'] as $only) {
            $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
            $tester->execute(['--only' => $only, '--dry-run' => true]);

            self::assertStringNotContainsString('Tetraminator', $tester->getDisplay(), $only);
        }
        self::assertSame([], $this->tetraminatorCalls());
    }

    public function testDryRunCountsTheDuePaymentsAndAsksNothing(): void
    {
        $payment = $this->aged(70 * self::MIN);
        $this->tetraminatorAnswers([self::tetraUnpaid()]);

        $tester = $this->command(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertConsoleContains('due: 1 of 1', $tester->getDisplay());
        self::assertSame([], $this->tetraminatorCalls());
        self::assertSame(PaymentStatus::PENDING, $this->paymentRow($payment)['status']);
    }

    public function testLogsCarryNoPayIdTokenKeyOrUrl(): void
    {
        $this->aged(24 * self::HOUR);
        $this->aged(5 * self::MIN, $this->newOrder());
        $this->tetraminatorAnswers([['transport' => 'GET https://api.tetraminator.com/v1/payment/inquiry/'.self::TETRA_PAY_ID.' X-API-KEY: '.self::TETRA_KEY], ['status' => 500, 'body' => ['message' => self::TETRA_PAY_ID]]]);

        $this->command();

        $text = self::getContainer()->get(RecordingLogger::class)->text();
        foreach ([self::TETRA_PAY_ID, self::TETRA_KEY, self::TETRA_TOKEN, 'https://shop.example'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
