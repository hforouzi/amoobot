<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\IncompleteOrderExpiryService;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class IncompleteOrderExpiryServiceTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    /**
     * @return array{Order, Payment}
     */
    private function oldOrderWithPayment(string $paymentStatus): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $payment = $this->createPayment($order, null, $paymentStatus);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'), $order->getId()]);
        $this->em->clear();

        return [$order, $payment];
    }

    private function expiry(): IncompleteOrderExpiryService
    {
        return self::getContainer()->get(IncompleteOrderExpiryService::class);
    }

    public function testExpiresUnpaidOrderAndRejectsItsPendingPayment(): void
    {
        [$order, $payment] = $this->oldOrderWithPayment(PaymentStatus::PENDING);

        $summary = $this->expiry()->expire(false, 24);

        self::assertSame(1, $summary['ordersExpired']);
        self::assertSame(OrderStatus::EXPIRED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::REJECTED, $this->row('payment', (int) $payment->getId())['status']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedStatuses(): iterable
    {
        yield 'needs review' => [PaymentStatus::NEEDS_REVIEW];
        yield 'provisioning failed' => [PaymentStatus::PROVISIONING_FAILED];
        yield 'confirmed' => [PaymentStatus::CONFIRMED];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedStatuses')]
    public function testNeverExpiresOrderWhosePaymentNeedsAnAdminOrIsPaid(string $paymentStatus): void
    {
        // Order status deliberately left at WAITING_PAYMENT (that is what NEEDS_REVIEW leaves behind).
        [$order, $payment] = $this->oldOrderWithPayment($paymentStatus);
        // A second, abandoned payment on the same order must not drag it into expiry either.
        $this->createPayment($this->em->find(Order::class, $order->getId()), null, PaymentStatus::PENDING);

        $summary = $this->expiry()->expire(false, 24);

        self::assertSame(0, $summary['ordersExpired']);
        self::assertSame(1, $summary['ordersSkippedPaymentInProgress']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame($paymentStatus, $this->row('payment', (int) $payment->getId())['status']);
    }

    #[Group('subprocess')]
    public function testWaitsForARunningSettlementAndThenLeavesTheOrderAlone(): void
    {
        [$order, $payment] = $this->oldOrderWithPayment(PaymentStatus::PENDING);
        $connection = $this->em->getConnection();

        // A settlement holds the order + payment locks (same order the settlement service takes them).
        $connection->beginTransaction();
        $connection->fetchOne('SELECT id FROM `order` WHERE id = ? FOR UPDATE', [$order->getId()]);
        $connection->fetchOne('SELECT id FROM payment WHERE id = ? FOR UPDATE', [$payment->getId()]);

        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $process = new Process([$php, 'bin/console', 'app:orders:expire-incomplete', '--hours=24', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 60);
        $process->start();
        usleep(2_500_000);
        self::assertTrue($process->isRunning(), 'expiry must block on the order lock: '.$process->getErrorOutput().$process->getOutput());

        // The settlement parks the payment for review and commits.
        $connection->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::NEEDS_REVIEW, $payment->getId()]);
        $connection->commit();
        $process->wait();

        self::assertSubprocessClean($process, 'app:orders:expire-incomplete');
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testDryRunChangesNothing(): void
    {
        [$order] = $this->oldOrderWithPayment(PaymentStatus::PENDING);

        self::assertSame(1, $this->expiry()->expire(true, 24)['ordersExpired']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
    }
}
