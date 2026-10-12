<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\UnderpaymentTolerancePolicy;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UnderpaymentToleranceTest extends IntegrationTestCase
{
    /**
     * @param array<string, mixed> $extraConfig
     *
     * @return array{Order, Payment}
     */
    private function cryptoPayment(array $extraConfig = []): array
    {
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's'] + $extraConfig);
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->setExpectation('10', 'usd');
        $this->em->flush();

        return [$order, $payment];
    }

    private function settle(Payment $payment, string $amount, string $currency): SettlementOutcome
    {
        $verification = new PaymentVerificationResult(success: true, paid: true, amount: $amount, currency: $currency);

        return self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway($verification), 'test')->outcome;
    }

    private function policy(): UnderpaymentTolerancePolicy
    {
        return self::getContainer()->get(UnderpaymentTolerancePolicy::class);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function policyCases(): iterable
    {
        yield 'crypto default' => [PaymentGatewayType::NOWPAYMENTS, [], '1'];
        yield 'fiat default' => [PaymentGatewayType::ZIBAL, [], '0'];
        yield 'manual card default' => [PaymentGatewayType::MANUAL_CARD, [], '0'];
        yield 'configured per gateway' => [PaymentGatewayType::ZIBAL, ['underpayment_tolerance_percent' => 0.5], '0.5'];
        yield 'crypto set to strict' => [PaymentGatewayType::NOWPAYMENTS, ['underpayment_tolerance_percent' => '0'], '0'];
        yield 'invalid falls back to strict' => [PaymentGatewayType::NOWPAYMENTS, ['underpayment_tolerance_percent' => '-3'], '0'];
        yield 'capped' => [PaymentGatewayType::NOWPAYMENTS, ['underpayment_tolerance_percent' => 50], UnderpaymentTolerancePolicy::MAX_PERCENT];
        yield 'empty uses default' => [PaymentGatewayType::NOWPAYMENTS, ['underpayment_tolerance_percent' => ''], '1'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('policyCases')]
    public function testPolicy(string $type, array $config, string $expected): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), $this->createGateway($type, $config));

        self::assertSame($expected, $this->policy()->percentFor($payment));
    }

    public function testCryptoShortfallWithinOnePercentIsConfirmedAndFlagged(): void
    {
        [$order, $payment] = $this->cryptoPayment();

        self::assertSame(SettlementOutcome::CONFIRMED, $this->settle($payment, '9.92', 'usd'));

        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(PaymentSettlementService::FLAG_UNDERPAID_TOLERATED, $row['amount_flag']);
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testShortfallBeyondToleranceGoesToReviewWithTheShortfallRecorded(): void
    {
        [$order, $payment] = $this->cryptoPayment();

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->settle($payment, '9.5', 'usd'));

        $row = $this->row('payment', (int) $payment->getId());
        self::assertSame(PaymentStatus::NEEDS_REVIEW, $row['status']);
        self::assertStringContainsString('shortfall 0.5 USD (5%), tolerance 1%', (string) $row['review_reason']);
        self::assertSame(0, $this->countServicesForOrder($order));
    }

    public function testFiatGatewayHasNoToleranceByDefault(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'm']), PaymentStatus::PENDING);

        self::assertSame(SettlementOutcome::NEEDS_REVIEW, $this->settle($payment, '1499990', 'IRR'));
        self::assertStringContainsString('shortfall 1 IRT', (string) $this->row('payment', (int) $payment->getId())['review_reason']);
    }

    public function testConfiguredToleranceIsUsed(): void
    {
        [$order, $payment] = $this->cryptoPayment(['underpayment_tolerance_percent' => 5]);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->settle($payment, '9.5', 'usd'));
        self::assertSame(1, $this->countServicesForOrder($order));
    }
}
