<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Bot\Application\TelegramUpdateHandler;
use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;

/**
 * Customer cancel goes through PaymentSettlementService::cancelByCustomer(): locked, re-checked,
 * only pending/submitted payments of an open order are rejected (confirmationSource customer_cancel).
 */
final class CustomerCancelTest extends IntegrationTestCase
{
    private const TELEGRAM_ID = '555001';

    /**
     * @return array{Order, Payment}
     */
    private function openOrder(string $paymentStatus = PaymentStatus::PENDING): array
    {
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, $paymentStatus);

        return [$order, $payment];
    }

    private function press(string $data): void
    {
        self::getContainer()->get(TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 1_000_000),
                'from' => ['id' => (int) self::TELEGRAM_ID, 'first_name' => 'T', 'username' => 'customer'],
                'message' => ['message_id' => 1, 'chat' => ['id' => (int) self::TELEGRAM_ID, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }

    private function assertCancelled(Order $order, Payment $payment): void
    {
        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::REJECTED, $row['status']);
        self::assertSame(Payment::SOURCE_CUSTOMER_CANCEL, $row['confirmation_source']);
        self::assertSame(OrderStatus::CANCELLED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testPaymentCancelButton(): void
    {
        [$order, $payment] = $this->openOrder();

        $this->press('payment_cancel:'.$payment->getId());

        $this->assertCancelled($order, $payment);
    }

    public function testOrderCancelButton(): void
    {
        [$order, $payment] = $this->openOrder();

        $this->press('order_cancel:'.$order->getId());

        $this->assertCancelled($order, $payment);
    }

    public function testIncompleteOrderCancel(): void
    {
        [$order, $payment] = $this->openOrder();

        $this->press('incomplete_order_cancel_confirm:order:'.$order->getId());

        $this->assertCancelled($order, $payment);
    }

    public function testCancelNeverOverwritesAConfirmedOrderEvenWithStaleEntities(): void
    {
        [$order, $payment] = $this->openOrder();
        // Entities in memory still say pending / waiting_payment; meanwhile a settlement committed.
        $this->em->getConnection()->executeStatement('UPDATE payment SET status = ? WHERE id = ?', [PaymentStatus::CONFIRMED, $payment->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ?, provisioned_at = NOW() WHERE id = ?', [OrderStatus::PROVISIONED, $order->getId()]);

        $this->press('payment_cancel:'.$payment->getId());
        $this->press('order_cancel:'.$order->getId());
        $this->press('incomplete_order_cancel_confirm:order:'.$order->getId());
        $result = self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($order);

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $result->outcome);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testPaymentInReviewBlocksTheCancel(): void
    {
        [$order, $payment] = $this->openOrder(PaymentStatus::NEEDS_REVIEW);

        $result = self::getContainer()->get(PaymentSettlementService::class)->cancelByCustomer($order);

        self::assertSame(SettlementOutcome::ALREADY_PROCESSED, $result->outcome);
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::WAITING_PAYMENT, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testOnlyTheCustomersOwnOrderCanBeCancelled(): void
    {
        $order = $this->createOrder($this->createUser('999888'), $this->createPlan());
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);

        $this->createUser(self::TELEGRAM_ID);
        $this->press('payment_cancel:'.$payment->getId());

        self::assertSame(PaymentStatus::PENDING, $this->row('payment', (int) $payment->getId())['status']);
    }
}
