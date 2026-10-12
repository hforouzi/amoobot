<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\ZibalGateway;
use App\Tests\Support\IntegrationTestCase;

final class GatewayAmountReportingTest extends IntegrationTestCase
{
    public function testZibalChargesRialAndReportsVerifiedRialAmount(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000));
        $payment = $this->createPayment($order, $gateway);
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => '777']);
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000, 'refNumber' => 'R1']);

        $zibal = self::getContainer()->get(ZibalGateway::class);
        $zibal->createPayment($payment, $order);
        $sent = json_decode((string) $this->http->requests('/v1/request')[0]['options']['body'], true);
        self::assertSame(1500000, $sent['amount'], 'Toman 150,000 must be charged as Rial 1,500,000');

        $verification = $zibal->verifyPayment($payment);
        self::assertSame('1500000', $verification->amount);
        self::assertSame('IRR', $verification->currency);
        self::assertSame(AmountMatchStatus::MATCH, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testZibalVerifyWithoutAmountIsMissing(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm']);
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), $gateway);
        $payment->setGatewayTransactionId('777');
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100]);

        $verification = self::getContainer()->get(ZibalGateway::class)->verifyPayment($payment);

        self::assertTrue($verification->paid);
        self::assertSame(AmountMatchStatus::MISSING, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testNowPaymentsReportsQuoteAndProratesPartialPayments(): void
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k']);
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), $gateway);
        $payment->setCryptoPaymentId('np_9')->setExpectation('12.5', 'usd');
        $now = self::getContainer()->get(NowPaymentsGateway::class);
        $matcher = new AmountMatcher();

        $this->http->onJson('/v1/payment/np_9', [
            'payment_status' => 'finished', 'price_amount' => 12.5, 'price_currency' => 'usd',
            'pay_amount' => 0.002, 'actually_paid' => 0.002,
        ]);
        $finished = $now->verifyPayment($payment);
        self::assertSame(['12.5', 'USD'], [$finished->amount, $finished->currency]);
        self::assertSame(AmountMatchStatus::MATCH, $matcher->matchVerification($payment, $finished)->status);

        $this->http->onJson('/v1/payment/np_9', [
            'payment_status' => 'partially_paid', 'price_amount' => 12.5, 'price_currency' => 'usd',
            'pay_amount' => 0.002, 'actually_paid' => 0.001,
        ]);
        $partial = $now->verifyPayment($payment);
        self::assertSame('6.25', $partial->amount);
        self::assertSame(AmountMatchStatus::UNDERPAID, $matcher->matchVerification($payment, $partial)->status);
    }
}
