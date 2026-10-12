<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentVerificationResult;

/**
 * Strict comparison of what a gateway says was paid against what this payment expects.
 *
 * Rules: a verification without amount AND currency is MISSING; a different currency (after
 * Rial/Toman normalization) is CURRENCY_MISMATCH; more is OVERPAID; less is UNDERPAID, unless the
 * shortfall is at most the tolerance (percent of the expected amount) (UNDERPAID_WITHIN_TOLERANCE).
 * Only MATCH, OVERPAID and UNDERPAID_WITHIN_TOLERANCE may be confirmed automatically.
 *
 * The tolerance comes from UnderpaymentTolerancePolicy (default 0 for fiat, 1 for crypto gateways).
 */
final class AmountMatcher
{
    public function matchVerification(Payment $payment, PaymentVerificationResult $verification, string $tolerancePercent = '0'): AmountMatch
    {
        [$expectedAmount, $expectedCurrency] = self::expectationFor($payment);

        return $this->match($expectedAmount, $expectedCurrency, $verification->amount, $verification->currency, $tolerancePercent);
    }

    /**
     * @param string $tolerancePercent accepted shortfall in percent of the expected amount, e.g. "1" or "0.5"
     */
    public function match(string $expectedAmount, string $expectedCurrency, ?string $actualAmount, ?string $actualCurrency, string $tolerancePercent = '0'): AmountMatch
    {
        $expected = AmountNormalizer::normalize($expectedAmount, $expectedCurrency);
        if (null === $expected) {
            throw new \InvalidArgumentException(sprintf('Invalid expected amount "%s %s".', $expectedAmount, $expectedCurrency));
        }
        $tolerance = Decimal::normalize($tolerancePercent);
        if (null === $tolerance) {
            throw new \InvalidArgumentException(sprintf('Invalid underpayment tolerance "%s".', $tolerancePercent));
        }

        $actualAmount = null === $actualAmount || '' === trim($actualAmount) ? null : trim($actualAmount);
        $actualCurrency = null === $actualCurrency || '' === trim($actualCurrency) ? null : trim($actualCurrency);
        $build = static fn (AmountMatchStatus $status, ?string $shortfall = null): AmountMatch => new AmountMatch(
            $status,
            $expected['amount'],
            $expected['currency'],
            $actualAmount,
            $actualCurrency,
            $shortfall,
            $tolerance,
        );

        if (null === $actualAmount || null === $actualCurrency) {
            return $build(AmountMatchStatus::MISSING);
        }

        $actual = AmountNormalizer::normalize($actualAmount, $actualCurrency);
        if (null === $actual) {
            return $build(AmountMatchStatus::MISSING);
        }

        if ($actual['currency'] !== $expected['currency']) {
            return $build(AmountMatchStatus::CURRENCY_MISMATCH);
        }

        $comparison = Decimal::compare($actual['amount'], $expected['amount']);
        if ($comparison >= 0) {
            return $build(0 === $comparison ? AmountMatchStatus::MATCH : AmountMatchStatus::OVERPAID);
        }

        // Exact decimal arithmetic: within tolerance <=> shortfall * 100 <= expected * tolerance.
        $shortfall = Decimal::subtract($expected['amount'], $actual['amount']);
        $withinTolerance = Decimal::compare($tolerance, '0') > 0
            && Decimal::compare(Decimal::multiply($shortfall, '100'), Decimal::multiply($expected['amount'], $tolerance)) <= 0;

        return $build($withinTolerance ? AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE : AmountMatchStatus::UNDERPAID, $shortfall);
    }

    /**
     * What the gateway must report for this payment.
     *
     * Gateways that charge in another currency (crypto) store their exact quote at createPayment
     * time (Payment::expectedAmount/expectedCurrency); otherwise it is the payable amount in Toman.
     *
     * @return array{string, string}
     */
    public static function expectationFor(Payment $payment): array
    {
        $amount = $payment->getExpectedAmount();
        $currency = $payment->getExpectedCurrency();
        if (null !== $amount && '' !== $amount && null !== $currency && '' !== $currency) {
            return [$amount, $currency];
        }

        return [(string) ($payment->getPayableAmount() ?? $payment->getAmount()), AmountNormalizer::SHOP_CURRENCY];
    }
}
