<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization: the wallet extension of PaymentSettlementService changes nothing for the existing order types.
 * New service / renewal / add-traffic orders settle, fail provisioning, retry, reject and cancel exactly as before and
 * never create a wallet or a ledger row.
 */
final class SettlementWalletIsolationTest extends IntegrationTestCase
{
    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private static function paid(string $amountToman): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx', amount: $amountToman, currency: 'IRT'));
    }

    private function assertNoWalletActivity(): void
    {
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serviceOrderTypes(): iterable
    {
        yield 'new service' => [OrderType::NEW_SERVICE];
        yield 'renewal' => [OrderType::RENEWAL];
        yield 'add traffic' => [OrderType::ADD_TRAFFIC];
    }

    /**
     * @return array{\App\Entity\Order, \App\Entity\Payment}
     */
    private function serviceOrder(string $type): array
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $target = OrderType::NEW_SERVICE === $type ? null : $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(100000, $inbound), $type, 100000, $target);

        return [$order, $this->createPayment($order, null, PaymentStatus::PENDING)];
    }

    #[DataProvider('serviceOrderTypes')]
    public function testConfirmedSettlementIsUnchanged(string $type): void
    {
        [$order, $payment] = $this->serviceOrder($type);

        $result = $this->settlement()->settle($payment, self::paid('100000'), 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertNotNull($result->vpnService);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        $this->assertNoWalletActivity();
    }

    #[DataProvider('serviceOrderTypes')]
    public function testProvisioningFailureAndRetryAreUnchanged(string $type): void
    {
        [$order, $payment] = $this->serviceOrder($type);
        $this->driver()->failNext();

        $failed = $this->settlement()->settle($payment, self::paid('100000'), 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome, 'service orders still record PROVISIONING_FAILED');
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);

        $retry = $this->settlement()->retryProvisioning($failed->payment ?? $payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        $this->assertNoWalletActivity();
    }

    #[DataProvider('serviceOrderTypes')]
    public function testRejectAndCancelAreUnchanged(string $type): void
    {
        [$order, $payment] = $this->serviceOrder($type);
        $rejected = $this->settlement()->reject($payment, 'no', 1, null, 'test');
        self::assertSame(SettlementOutcome::REJECTED, $rejected->outcome);
        self::assertSame(OrderStatus::FAILED, $this->row('`order`', (int) $order->getId())['status']);

        [$order2] = $this->serviceOrder($type);
        $cancelled = $this->settlement()->cancelByCustomer($order2);
        self::assertSame(SettlementOutcome::REJECTED, $cancelled->outcome);
        self::assertSame(OrderStatus::CANCELLED, $this->row('`order`', (int) $order2->getId())['status']);
        $this->assertNoWalletActivity();
    }

    public function testAnAmountMismatchStillGoesToReview(): void
    {
        [, $payment] = $this->serviceOrder(OrderType::NEW_SERVICE);

        $result = $this->settlement()->settle($payment, self::paid('99999'), 'test');

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        $this->assertNoWalletActivity();
    }
}
