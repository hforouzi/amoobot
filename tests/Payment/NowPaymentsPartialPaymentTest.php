<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * partially_paid is only reported with an amount when it can be pro-rated from BOTH pay_amount and
 * actually_paid; otherwise the amount is missing and the payment goes to NEEDS_REVIEW.
 */
final class NowPaymentsPartialPaymentTest extends IntegrationTestCase
{
    private function payment(bool $invoiceMode = false): Payment
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's']);
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound())), $gateway, PaymentStatus::PENDING);
        $invoiceMode ? $payment->setCryptoInvoiceId('inv_7') : $payment->setCryptoPaymentId('np_7');
        $payment->setExpectation('12.5', 'usd');
        $this->em->flush();

        return $payment;
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function partialResponses(): iterable
    {
        $base = ['payment_status' => 'partially_paid', 'price_amount' => 12.5, 'price_currency' => 'usd'];
        yield 'both amounts: pro-rated' => [$base + ['pay_amount' => 0.002, 'actually_paid' => 0.001], '6.25'];
        yield 'actually_paid missing' => [$base + ['pay_amount' => 0.002], null];
        yield 'pay_amount missing' => [$base + ['actually_paid' => 0.001], null];
        yield 'actually_paid not numeric' => [$base + ['pay_amount' => 0.002, 'actually_paid' => 'n/a'], null];
        yield 'pay_amount zero' => [$base + ['pay_amount' => 0, 'actually_paid' => 0.001], null];
        yield 'neither' => [$base, null];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('partialResponses')]
    public function testPartialPaymentAmount(array $response, ?string $expectedAmount): void
    {
        $payment = $this->payment();
        $this->http->onJson('/v1/payment/np_7', $response);

        $verification = self::getContainer()->get(NowPaymentsGateway::class)->verifyPayment($payment);

        self::assertTrue($verification->paid);
        self::assertSame($expectedAmount, $verification->amount);
        $expectedStatus = null === $expectedAmount ? AmountMatchStatus::MISSING : AmountMatchStatus::UNDERPAID;
        self::assertSame($expectedStatus, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testFinishedPaymentWithoutCryptoAmountsStillReportsThePrice(): void
    {
        $payment = $this->payment();
        $this->http->onJson('/v1/payment/np_7', ['payment_status' => 'finished', 'price_amount' => 12.5, 'price_currency' => 'usd']);

        $verification = self::getContainer()->get(NowPaymentsGateway::class)->verifyPayment($payment);

        self::assertSame(['12.5', 'USD'], [$verification->amount, $verification->currency]);
    }

    public function testInvoiceModePartialResponseWithoutCryptoAmountsGoesToReview(): void
    {
        $payment = $this->payment(invoiceMode: true);
        // Invoice endpoint: status + price only, no pay_amount / actually_paid.
        $this->http->onJson('/v1/invoice/inv_7', ['id' => 'inv_7', 'invoice_status' => 'partially_paid', 'price_amount' => 12.5, 'price_currency' => 'usd', 'order_id' => '1']);

        $verification = self::getContainer()->get(NowPaymentsGateway::class)->verifyPayment($payment);
        self::assertTrue($verification->paid);
        self::assertNull($verification->amount, 'never the full price for a partial payment');

        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'test');
        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $result->outcome);
        self::assertSame(0, $this->countServicesForOrder($payment->getOrder()));
    }
}
