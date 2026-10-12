<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

/**
 * The single place that defines money units.
 *
 * Unit policy (see docs/en/payments/amount-units.md):
 *   - Order::amount, Payment::amount and Payment::payableAmount are integers in TOMAN.
 *     Inside the app Toman is written as the pseudo-ISO code "IRT".
 *   - Rial gateways (Zibal, NovinoPay, ...) work in IRR; 1 Toman = 10 Rial.
 *     Convert outgoing amounts with toRial() and let normalize() convert reported ones back.
 *   - Any other currency (USD, USDT, ...) is passed through upper-cased; no FX conversion happens here.
 */
final class AmountNormalizer
{
    public const SHOP_CURRENCY = 'IRT';
    public const RIAL = 'IRR';
    public const RIAL_PER_TOMAN = 10;

    private const TOMAN_ALIASES = ['IRT', 'TOMAN', 'TMN'];
    private const RIAL_ALIASES = ['IRR', 'RIAL'];

    /**
     * Converts a shop amount (Toman) into the Rial amount a rial gateway must be charged.
     */
    public static function toRial(int $shopAmount): int
    {
        return $shopAmount * self::RIAL_PER_TOMAN;
    }

    public static function currencyCode(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (in_array($code, self::TOMAN_ALIASES, true)) {
            return self::SHOP_CURRENCY;
        }
        if (in_array($code, self::RIAL_ALIASES, true)) {
            return self::RIAL;
        }

        return $code;
    }

    /**
     * Normalizes a reported amount: Rial is converted to Toman, everything else keeps its currency.
     *
     * @return array{amount: string, currency: string}|null null when the amount is not a valid non-negative decimal
     */
    public static function normalize(string $amount, string $currency): ?array
    {
        $decimal = Decimal::normalize($amount);
        $code = self::currencyCode($currency);
        if (null === $decimal || '' === $code) {
            return null;
        }

        if (self::RIAL === $code) {
            return ['amount' => Decimal::divideByPowerOfTen($decimal, 1), 'currency' => self::SHOP_CURRENCY];
        }

        return ['amount' => $decimal, 'currency' => $code];
    }
}
