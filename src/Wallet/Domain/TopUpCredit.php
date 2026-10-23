<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

use App\Entity\Payment;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\Money\Decimal;

/**
 * What a confirmed WALLET_TOPUP payment credits to the wallet: what was actually received, in Toman.
 *
 * The gateway's verified amount (Payment::verifiedAmount/Currency, recorded by the settlement before it fulfils) is
 * compared with the same expectation the AmountMatcher checked (AmountMatcher::expectationFor(): the crypto quote, else
 * the payable amount in Toman). Received less than expected (an underpayment within the gateway's tolerance, or one an
 * admin approved): floor(ordered * received / expected). Received as much or more: the ordered amount, never more (an
 * overpayment stays flagged for the admins). No reported amount (card to card approved by an admin): the ordered
 * amount. The note records received vs credited on the ledger row.
 */
final readonly class TopUpCredit
{
    private function __construct(
        public int $amount,
        public ?string $note,
    ) {
    }

    public static function for(int $ordered, Payment $payment): self
    {
        $receivedAmount = $payment->getVerifiedAmount();
        $receivedCurrency = $payment->getVerifiedCurrency();
        if (null === $receivedAmount || null === $receivedCurrency) {
            return new self($ordered, null);
        }

        [$expectedAmount, $expectedCurrency] = AmountMatcher::expectationFor($payment);
        $expected = AmountNormalizer::normalize($expectedAmount, $expectedCurrency);
        $received = AmountNormalizer::normalize($receivedAmount, $receivedCurrency);
        if (null === $expected || null === $received || $expected['currency'] !== $received['currency'] || 0 === Decimal::compare($expected['amount'], '0')) {
            // Not comparable (only reachable through an admin's approval of a payment parked for review): the
            // admin's decision stands, as before; the note keeps what the gateway said.
            return new self($ordered, sprintf('received %s %s, expected %s %s (not comparable); credited the %d ordered', $receivedAmount, $receivedCurrency, $expectedAmount, $expectedCurrency, $ordered));
        }

        $credit = Decimal::floorShare($ordered, $received['amount'], $expected['amount']);

        return new self($credit, sprintf('received %s %s of %s %s expected; credited %d of %d ordered', $received['amount'], $received['currency'], $expected['amount'], $expected['currency'], $credit, $ordered));
    }
}
