<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;

/**
 * Orders paid through the fake provider of InvoicingGatewayPlugin. For an IntegrationTestCase. The caller's setUp() runs
 * setUpInvoiceRows() and its tearDown() runs tearDownConstrainedGateway().
 */
trait InvoiceRowFixture
{
    use ConstrainedGatewayFixture;

    private const PRICE = 150000;

    private StorePaymentMethod $invoiceMethod;
    private Order $order;

    private function setUpInvoiceRows(): void
    {
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->invoiceMethod = $this->constrainedMethod();
        $this->order = $this->createOrder($this->createUser(), $this->createPlan(self::PRICE, $this->createTestInbound()));
    }

    /** Another store method of the same provider: a second gateway row to switch to. */
    private function otherInvoiceMethod(): StorePaymentMethod
    {
        return $this->constrainedMethod([], 'درگاه دوم', 60);
    }

    /** A core gateway, so one with no lifetime. */
    private function storeMethod(PaymentGateway $gateway): StorePaymentMethod
    {
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle('بدون مهلت')->setIsActive(true)->setSortOrder(70);
        $this->em->persist($method);
        $this->em->flush();

        return $method;
    }

    private function start(?StorePaymentMethod $method = null): PaymentStart
    {
        return self::getContainer()->get(PaymentStarter::class)->start($this->order, $method ?? $this->invoiceMethod);
    }

    /** A row of the order on the invoice gateway, written directly; a null $transactionId makes it a reservation. */
    private function invoiceRow(?string $transactionId, string $expiresAt): Payment
    {
        $payment = $this->createPayment($this->order, $this->invoiceMethod->getGateway(), 'pending');
        $payment
            ->setStorePaymentMethod($this->invoiceMethod)
            ->setGatewayTransactionId($transactionId)
            ->setAuthority(null === $transactionId ? null : 'hash-'.$transactionId)
            ->setPaymentUrl(null === $transactionId ? null : 'https://provider.test/'.$transactionId)
            ->setExpiresAt(new \DateTimeImmutable($expiresAt));
        $this->em->flush();

        return $payment;
    }

    /** @return array<string, mixed> */
    private function paymentRow(Payment $payment): array
    {
        return $this->row('payment', (int) $payment->getId());
    }

    private function paymentRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = ?', [$this->order->getId()]);
    }

    /** Seconds from now to the row's expires_at. */
    private function secondsLeft(Payment $payment): int
    {
        return (new \DateTimeImmutable((string) $this->paymentRow($payment)['expires_at']))->getTimestamp() - time();
    }
}
