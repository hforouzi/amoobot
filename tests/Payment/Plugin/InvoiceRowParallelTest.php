<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\InvoiceRowFixture;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The races around one invoice, in real processes on the real database. TEST_PANEL_DELAY_MS keeps a settlement inside its
 * locks while the panel answers, and TEST_INVOICE_DELAY_MS keeps createPayment() running, outside any lock.
 */
#[Group('subprocess')]
final class InvoiceRowParallelTest extends IntegrationTestCase
{
    use InvoiceRowFixture;
    use ParallelStart;
    use SubprocessAssertions;

    private string $callsFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->callsFile = sys_get_temp_dir().'/invoice-race-'.bin2hex(random_bytes(6)).'.log';
        $this->setUpInvoiceRows();
    }

    protected function tearDown(): void
    {
        @unlink($this->callsFile);
        $this->removeStart();
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    /**
     * @param list<array{string, int, string|int, float}> $sides     [action, id, argument, offset from the common start]
     * @param array<string, string>                       $env
     *
     * @return list<string> what each side printed, sorted
     */
    private function race(array $sides, array $env = []): array
    {
        $processes = [];
        foreach ($sides as [$action, $id, $argument, $offset]) {
            $process = new Process(
                [(new PhpExecutableFinder())->find(false) ?: 'php', 'tests/Support/bin/invoice-race.php', $action, (string) $id, (string) $argument, $this->startAt($offset)],
                dirname(__DIR__, 3),
                ['APP_ENV' => 'test', 'TEST_INVOICE_CALLS_FILE' => $this->callsFile] + $env,
                null,
                240
            );
            $process->start();
            $processes[] = $process;
        }
        $this->releaseStart($processes);

        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $outputs[] = self::assertSubprocessClean($process, 'invoice race');
        }
        $this->removeStart();
        sort($outputs);
        $this->em->clear();

        return $outputs;
    }

    private function providerCalls(): int
    {
        return is_file($this->callsFile) ? count(file($this->callsFile, FILE_IGNORE_NEW_LINES) ?: []) : 0;
    }

    public function testTwoStartsMakeOneProviderCallAndOneInvoice(): void
    {
        $orderId = (int) $this->order->getId();
        $method = (int) $this->invoiceMethod->getId();

        $outputs = $this->race([['start', $orderId, $method, 0.0], ['start', $orderId, $method, 0.0]], ['TEST_INVOICE_DELAY_MS' => '2000']);

        self::assertSame(['start:ready', 'start:refused'], $outputs, 'the second start arrives during the first one\'s createPayment()');
        self::assertSame(1, $this->providerCalls());
        self::assertSame(1, $this->paymentRows());
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM payment WHERE order_id = ?', [$orderId]);
        self::assertIsArray($row);
        self::assertNotNull($row['gateway_transaction_id']);
        self::assertSame(PaymentStatus::PENDING, $row['status']);
        self::assertGreaterThan(3500, (new \DateTimeImmutable((string) $row['expires_at']))->getTimestamp() - time());
    }

    /**
     * @return iterable<string, array{float, float, int}> offsets of the expiry and the settlement, the panel's delay
     */
    public static function expiryRaces(): iterable
    {
        yield 'together' => [0.0, 0.0, 300];
        yield 'the settlement holds the locks first' => [0.6, 0.0, 2000];
        yield 'the expiry goes first' => [0.0, 0.5, 0];
    }

    #[DataProvider('expiryRaces')]
    public function testExpiryAgainstSettlementNeverIgnoresTheMoney(float $expireAt, float $settleAt, int $panelDelay): void
    {
        $payment = $this->invoiceRow('race', '-1 minute');
        $id = (int) $payment->getId();

        $outputs = $this->race(
            [['expire', $id, 0, $expireAt], ['settle', $id, self::PRICE, $settleAt]],
            ['TEST_PANEL_DELAY_MS' => (string) $panelDelay],
        );

        $status = $this->paymentRow($payment)['status'];
        self::assertContains($status, [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW], implode(', ', $outputs));
        $expected = PaymentStatus::CONFIRMED === $status
            ? ['expire:already_processed', 'settle:confirmed']
            : ['expire:rejected', 'settle:needs_review'];
        self::assertSame($expected, $outputs);
        self::assertSame(PaymentStatus::CONFIRMED === $status ? 1 : 0, $this->countServicesForOrder($this->order));
        if ($expireAt > $settleAt) {
            self::assertSame(PaymentStatus::CONFIRMED, $status, 'the settlement held the locks');
        }
        if ($expireAt < $settleAt) {
            self::assertSame(PaymentStatus::NEEDS_REVIEW, $status, 'the expiry went first');
        }
    }

    /**
     * @return iterable<string, array{float, float, int}> offsets of the start and the settlement, the panel's delay
     */
    public static function switchRaces(): iterable
    {
        yield 'together' => [0.0, 0.0, 300];
        yield 'the settlement holds the locks first' => [0.6, 0.0, 2000];
        yield 'the switch goes first' => [0.0, 0.5, 0];
    }

    #[DataProvider('switchRaces')]
    public function testAGatewaySwitchAgainstASettlementOfTheOldRowNeverIgnoresTheMoney(float $startAt, float $settleAt, int $panelDelay): void
    {
        $old = $this->invoiceRow('race', '+30 minutes');
        $id = (int) $old->getId();
        $other = (int) $this->otherInvoiceMethod()->getId();

        $outputs = $this->race(
            [['start', (int) $this->order->getId(), $other, $startAt], ['settle', $id, self::PRICE, $settleAt]],
            ['TEST_PANEL_DELAY_MS' => (string) $panelDelay],
        );

        $row = $this->paymentRow($old);
        self::assertContains($row['status'], [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW], implode(', ', $outputs));
        self::assertSame('race', $row['gateway_transaction_id'], 'the old invoice keeps its reference');
        self::assertContains(PaymentStatus::CONFIRMED === $row['status'] ? 'settle:confirmed' : 'settle:needs_review', $outputs);
        self::assertSame(PaymentStatus::CONFIRMED === $row['status'] ? 1 : 0, $this->countServicesForOrder($this->order));
        if ($startAt > $settleAt) {
            self::assertSame(PaymentStatus::CONFIRMED, $row['status'], 'the settlement held the locks');
        }
        if ($startAt < $settleAt) {
            self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status'], 'the switch went first');
        }
        $pending = $this->em->getRepository(Payment::class)->findBy(['order' => $this->order->getId(), 'status' => PaymentStatus::PENDING]);
        if (PaymentStatus::CONFIRMED === $row['status']) {
            // The settlement paid the order first: the start finds it paid under the lock and creates no invoice.
            self::assertContains('start:refused', $outputs);
            self::assertSame(0, $this->providerCalls(), 'no invoice for a paid order');
            self::assertSame([], $pending);
        } else {
            self::assertContains('start:ready', $outputs);
            self::assertSame(1, $this->providerCalls(), 'the new row got its one invoice');
            self::assertNotEmpty($pending);
        }
    }
}
