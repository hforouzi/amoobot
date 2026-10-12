<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class PaymentSettlementServiceTest extends IntegrationTestCase
{
    private const PRICE = 150000; // Toman

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private static function paid(?string $amount, ?string $currency): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx1', amount: $amount, currency: $currency));
    }

    /**
     * @return array{Order, Payment}
     */
    private function newServiceOrder(string $paymentStatus = PaymentStatus::PENDING): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(self::PRICE, $this->createTestInbound()));

        return [$order, $this->createPayment($order, null, $paymentStatus)];
    }

    // --- happy paths ---------------------------------------------------------------------------

    public function testGatewayPaymentWithMatchingRialAmountProvisionsNewService(): void
    {
        [$order, $payment] = $this->newServiceOrder();

        $result = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame('test', $row['confirmation_source']);
        self::assertSame('1500000', $row['verified_amount']);
        self::assertNull($row['amount_flag']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertNotEmpty($this->http->telegramTexts(), 'customer is notified after commit');
    }

    public function testManualCardApprovalByAdminRecordsApprover(): void
    {
        [$order, $payment] = $this->newServiceOrder(PaymentStatus::SUBMITTED);

        $result = $this->settlement()->approveByAdmin($payment, 7, '555', 'easyadmin_payment_approval');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(7, (int) $row['confirmed_by_admin_id']);
        self::assertSame('555', $row['confirmed_by_telegram_id']);
    }

    public function testRenewalExtendsTargetService(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(self::PRICE, $inbound), OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(1, $this->driver()->renewCalls);
        self::assertSame(100, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb'], '50 GB carried over + 50 GB renewal');
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testAddTrafficIncreasesLimit(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(self::PRICE, $inbound), OrderType::ADD_TRAFFIC, 20000, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);

        $result = $this->settlement()->settle($payment, self::paid('20000', 'IRT'), 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(60, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb']);
    }

    // --- idempotency / concurrency ---------------------------------------------------------------

    public function testSecondSettleIsIdempotent(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'webhook');
        $messagesAfterFirst = count($this->http->telegramTexts());

        $again = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'return_page');
        $admin = $this->settlement()->approveByAdmin($payment, 1, null, 'admin');

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $again->outcome);
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $admin->outcome);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(1, $this->driver()->createCalls);
        self::assertCount($messagesAfterFirst, $this->http->telegramTexts(), 'no side effects on repeat');
    }

    public function testSecondPaymentForFulfilledOrderGoesToReview(): void
    {
        [$order, $first] = $this->newServiceOrder();
        $second = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->settlement()->settle($first, self::paid('1500000', 'IRR'), 'test');

        $result = $this->settlement()->settle($second, self::paid('1500000', 'IRR'), 'test');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        self::assertStringContainsString('duplicate', (string) $this->row('payment', (int) $second->getId())['review_reason']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testConcurrentSettlementsCreateExactlyOneService(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $php = $this->phpForSubprocesses();
        $script = dirname(__DIR__).'/Support/bin/settle-payment.php';
        $env = ['TEST_PANEL_DELAY_MS' => '1500', 'APP_ENV' => 'test'];

        $processes = [];
        for ($i = 0; $i < 2; ++$i) {
            $process = new Process([$php, $script, (string) $payment->getId(), (string) self::PRICE], dirname(__DIR__, 2), $env, null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);

        self::assertSame([SettlementOutcome::ALREADY_PROCESSED->value, SettlementOutcome::CONFIRMED->value], $outcomes);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testSettlementWaitsForTheRowLockAndRereadsState(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $connection = $this->em->getConnection();

        // Hold the order + payment row locks as a competing settlement would.
        $connection->beginTransaction();
        $connection->fetchOne('SELECT id FROM `order` WHERE id = ? FOR UPDATE', [$order->getId()]);
        $connection->fetchOne('SELECT id FROM payment WHERE id = ? FOR UPDATE', [$payment->getId()]);

        $php = $this->phpForSubprocesses();
        $process = new Process([$php, dirname(__DIR__).'/Support/bin/settle-payment.php', (string) $payment->getId(), (string) self::PRICE], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 60);
        $process->start();
        usleep(2_500_000);
        self::assertTrue($process->isRunning(), 'settlement must block on the row lock: '.$process->getErrorOutput());

        // The competitor finishes first.
        $connection->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::CONFIRMED, $payment->getId()]);
        $connection->commit();
        $process->wait();

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED->value, trim($process->getOutput()), $process->getErrorOutput());
        self::assertSame(0, $this->countServicesForOrder($order), 'stale in-memory status must not be trusted');
    }

    /**
     * Subprocesses share var/cache/test; generate Doctrine proxies up front so two processes never
     * race on writing the same proxy file (fails on Windows with "Access is denied").
     */
    private function phpForSubprocesses(): string
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    // --- provisioning failure ----------------------------------------------------------------

    public function testFlushFailureThatClosesTheEntityManagerIsRecorded(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        // Any exception inside flush() closes the entity manager; fail while the new service is being inserted.
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof VpnService) {
                        throw new \RuntimeException('Simulated database failure');
                    }
                }
            }
        });

        $result = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertTrue($this->em->isOpen(), 'the shared entity manager is usable again');
    }

    public function testProvisioningFailureIsRolledBackMarkedAndRetryable(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $this->driver()->failNext();

        $failed = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'webhook');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $row['status'], 'never CONFIRMED without a service');
        self::assertNull($row['confirmed_at']);
        self::assertStringContainsString('Simulated panel outage', (string) $row['provisioning_error']);
        self::assertStringNotContainsString('supersecret123', (string) $row['provisioning_error'], 'secrets are redacted');
        self::assertSame(1, (int) $row['provisioning_attempts']);
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'ساخت سرویس ناموفق')), 'admins alerted');

        // A late webhook must not re-run provisioning; only the explicit retry may.
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->settle($failed->payment ?? $payment, self::paid('1500000', 'IRR'), 'late_webhook')->outcome);

        $retry = $this->settlement()->retryProvisioning($failed->payment ?? $payment, 3, null, 'easyadmin_retry_provisioning');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(2, (int) $row['provisioning_attempts']);
        self::assertNull($row['provisioning_error']);
    }

    public function testRenewalFailureLeavesServiceUntouched(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(self::PRICE, $inbound), OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->driver()->failNext();

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome);
        self::assertSame(50, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb']);
    }

    // --- strict amount check -------------------------------------------------------------------

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function reviewCases(): iterable
    {
        yield 'missing amount' => [null, 'IRR'];
        yield 'missing currency' => ['1500000', null];
        yield 'wrong currency' => ['150000', 'USD'];
        yield 'underpaid' => ['1499990', 'IRR'];
        yield 'toman sent as rial' => ['150000', 'IRR'];
    }

    #[DataProvider('reviewCases')]
    public function testMismatchGoesToReviewWithoutProvisioning(?string $amount, ?string $currency): void
    {
        [$order, $payment] = $this->newServiceOrder();

        $result = $this->settlement()->settle($payment, self::paid($amount, $currency), 'test');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(0, $this->driver()->createCalls);
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertNotEmpty($row['review_reason']);
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'نیازمند بررسی')), 'admins alerted');
    }

    public function testReviewedPaymentCanBeApprovedByAdmin(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $review = $this->settlement()->settle($payment, self::paid('100', 'IRR'), 'test');

        $approved = $this->settlement()->approveByAdmin($review->payment ?? $payment, 9, null, 'easyadmin_payment_approval');

        self::assertTrue($approved->isConfirmed(), $approved->message);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testGatewayCannotSettleAReviewedPayment(): void
    {
        [$order, $payment] = $this->newServiceOrder();
        $this->settlement()->settle($payment, self::paid('100', 'IRR'), 'test');

        $result = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'retry_webhook');

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $result->outcome);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testOverpaymentIsConfirmedAndFlagged(): void
    {
        [$order, $payment] = $this->newServiceOrder();

        $result = $this->settlement()->settle($payment, self::paid('2000000', 'IRR'), 'test');

        self::assertTrue($result->isConfirmed());
        self::assertSame(PaymentSettlementService::FLAG_OVERPAID, $this->row('payment', (int) $payment->getId())['amount_flag']);
        self::assertNotEmpty(array_filter($this->http->telegramTexts(), static fn (string $t): bool => str_contains($t, 'بیش از مبلغ')));
    }

    public function testUnpaidVerificationCannotBeSettled(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: false, amount: '1500000', currency: 'IRR'));
    }

    // --- reject ----------------------------------------------------------------------------------

    public function testRejectAndNoRejectAfterConfirm(): void
    {
        [$order, $payment] = $this->newServiceOrder(PaymentStatus::SUBMITTED);
        $rejected = $this->settlement()->reject($payment, 'bad receipt', 2, null, 'admin');
        self::assertSame(SettlementOutcome::REJECTED, $rejected->outcome);
        self::assertSame(OrderStatus::FAILED, $this->row('`order`', (int) $order->getId())['status']);

        [, $confirmed] = $this->newServiceOrder(PaymentStatus::SUBMITTED);
        $this->settlement()->approveByAdmin($confirmed, 1, null, 'admin');
        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $this->settlement()->reject($confirmed, null, 2, null, 'admin')->outcome);
    }
}
