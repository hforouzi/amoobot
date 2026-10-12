<?php

declare(strict_types=1);

namespace App\Tests\Payment\Contract;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Webhook\GatewayNotificationProcessor;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contract every payment gateway driver must pass. Extend it for each driver (core or plugin).
 *
 *  1. a paid verification reports amount AND currency, matching the order
 *  2. an unpaid verification is never "paid"
 *  3. a notification claiming "paid" while the provider says otherwise never creates a service
 *  4. a notification cannot select a payment by our internal ids (payment id / order id)
 */
abstract class GatewayContractTestCase extends IntegrationTestCase
{
    protected const PRICE_TOMAN = 150000;

    abstract protected function gatewayDriver(): PaymentGatewayInterface;

    abstract protected function createGatewayEntity(): PaymentGateway;

    /** Store the references createPayment() would store (track id, invoice id, ...). */
    abstract protected function prepareCreatedPayment(Payment $payment): void;

    abstract protected function providerSaysPaid(Payment $payment): void;

    abstract protected function providerSaysUnpaid(Payment $payment): void;

    /** An (authenticated, where the provider supports it) notification whose body claims success. */
    abstract protected function forgedPaidNotification(Payment $payment): Request;

    /** A notification that names only our internal payment/order ids. */
    abstract protected function notificationWithInternalIdsOnly(Payment $payment): Request;

    protected function channel(): string
    {
        return NotificationAwareGatewayInterface::CHANNEL_WEBHOOK;
    }

    /**
     * @return array{Order, Payment, PaymentGateway}
     */
    protected function createdPayment(): array
    {
        $gateway = $this->createGatewayEntity();
        $order = $this->createOrder($this->createUser(), $this->createPlan(self::PRICE_TOMAN, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $this->prepareCreatedPayment($payment);
        $this->em->flush();

        return [$order, $payment, $gateway];
    }

    public function testPaidVerificationReportsMatchingAmountAndCurrency(): void
    {
        [, $payment] = $this->createdPayment();
        $this->providerSaysPaid($payment);

        $verification = $this->gatewayDriver()->verifyPayment($payment);

        self::assertTrue($verification->success && $verification->paid, (string) $verification->message);
        self::assertNotNull($verification->amount, 'verifyPayment() must report the paid amount');
        self::assertNotNull($verification->currency, 'verifyPayment() must report the currency');
        self::assertSame(AmountMatchStatus::MATCH, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testUnpaidVerificationIsNotPaid(): void
    {
        [, $payment] = $this->createdPayment();
        $this->providerSaysUnpaid($payment);

        self::assertFalse($this->gatewayDriver()->verifyPayment($payment)->paid);
    }

    public function testForgedPaidNotificationDoesNotCreateAService(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $this->providerSaysUnpaid($payment);

        $outcome = $this->processor()->process([$gateway], $this->forgedPaidNotification($payment), $this->channel(), 'contract_test');

        self::assertNotSame('confirmed', $outcome->code);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertNotSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testNotificationCannotSelectPaymentByInternalIds(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $this->providerSaysPaid($payment);

        $outcome = $this->processor()->process([$gateway], $this->notificationWithInternalIdsOnly($payment), $this->channel(), 'contract_test');

        self::assertContains($outcome->code, ['payment_not_found', 'invalid_signature', 'unauthorized'], $outcome->code);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testGenuinePaidNotificationIsSettledOnce(): void
    {
        [$order, $payment, $gateway] = $this->createdPayment();
        $this->providerSaysPaid($payment);

        $first = $this->processor()->process([$gateway], $this->forgedPaidNotification($payment), $this->channel(), 'contract_test');
        $second = $this->processor()->process([$gateway], $this->forgedPaidNotification($payment), $this->channel(), 'contract_test');

        self::assertSame('confirmed', $first->code);
        self::assertSame('already_confirmed', $second->code);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    protected function processor(): GatewayNotificationProcessor
    {
        return self::getContainer()->get(GatewayNotificationProcessor::class);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    protected static function request(string $method, array $query = [], array $json = [], array $headers = []): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/contract', $method, $query, [], [], $server, [] === $json ? null : (string) json_encode($json));
    }
}
