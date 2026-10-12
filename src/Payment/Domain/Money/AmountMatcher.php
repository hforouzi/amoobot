<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

use App\Entity\Payment;
use App\Payment\Domain\Dto\PaymentVerificationResult;

/**
 * Strict comparison of what a gateway says was paid against what this payment expects.
 *
 * Rules: a verification without amount AND currency is MISSING; a different currency (after
 * Rial/Toman normalization) is CURRENCY_MISMATCH; less than expected is UNDERPAID; more is OVERPAID.
 * Only MATCH and OVERPAID may be confirmed automatically.
 */
final class AmountMatcher
{
    public function matchVerification(Payment $payment, PaymentVerificationResult $verification): AmountMatch
    {
        [$expectedAmount, $expectedCurrency] = self::expectationFor($payment);

        return $this->match($expectedAmount, $expectedCurrency, $verification->amount, $verification->currency);
    }

    public function match(string $expectedAmount, string $expectedCurrency, ?string $actualAmount, ?string $actualCurrency): AmountMatch
    {
        $expected = AmountNormalizer::normalize($expectedAmount, $expectedCurrency);
        if (null === $expected) {
            throw new \InvalidArgumentException(sprintf('Invalid expected amount "%s %s".', $expectedAmount, $expectedCurrency));
        }

        $actualAmount = null === $actualAmount || '' === trim($actualAmount) ? null : trim($actualAmount);
        $actualCurrency = null === $actualCurrency || '' === trim($actualCurrency) ? null : trim($actualCurrency);
        $build = static fn (AmountMatchStatus $status): AmountMatch => new AmountMatch(
            $status,
            $expected['amount'],
            $expected['currency'],
            $actualAmount,
            $actualCurrency,
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

        return $build(match (Decimal::compare($actual['amount'], $expected['amount'])) {
            0 => AmountMatchStatus::MATCH,
            1 => AmountMatchStatus::OVERPAID,
            default => AmountMatchStatus::UNDERPAID,
        });
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
