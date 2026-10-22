<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The wallet's switches and limits (Setting rows, edited on the web settings page, SETTINGS_MANAGE). Amounts are
 * integer Toman. With the wallet off nothing about it is shown to customers and no top-up or wallet payment can
 * start; money already in wallets stays there.
 */
class WalletSettings
{
    public const ENABLED = 'wallet.enabled';
    public const TOPUP_MIN = 'wallet.topup_min';
    public const TOPUP_MAX = 'wallet.topup_max';
    public const QUICK_AMOUNTS = 'wallet.topup_quick_amounts';
    public const PAYMENT_ENABLED = 'wallet.payment_enabled';

    public const DEFAULT_TOPUP_MIN = 10000;
    public const DEFAULT_TOPUP_MAX = 10000000;
    public const DEFAULT_QUICK_AMOUNTS = '50000,100000,200000,500000';

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }

    /** Paying orders from the wallet (default on once the wallet is enabled). */
    public function paymentEnabled(): bool
    {
        return $this->enabled() && $this->settings->getBool(self::PAYMENT_ENABLED, true);
    }

    public function topUpMin(): int
    {
        return max(1, $this->int(self::TOPUP_MIN, self::DEFAULT_TOPUP_MIN));
    }

    public function topUpMax(): int
    {
        return max($this->topUpMin(), $this->int(self::TOPUP_MAX, self::DEFAULT_TOPUP_MAX));
    }

    public function isTopUpAmountAllowed(int $amount): bool
    {
        return $amount >= $this->topUpMin() && $amount <= $this->topUpMax();
    }

    /**
     * The quick-amount buttons: the configured ones inside the allowed range, ascending, at most six.
     *
     * @return list<int>
     */
    public function quickAmounts(): array
    {
        $amounts = [];
        foreach (explode(',', (string) $this->settings->get(self::QUICK_AMOUNTS, self::DEFAULT_QUICK_AMOUNTS)) as $part) {
            $part = trim($part);
            if (1 === preg_match('/^\d{1,12}$/', $part) && $this->isTopUpAmountAllowed((int) $part)) {
                $amounts[(int) $part] = (int) $part;
            }
        }
        sort($amounts);

        return array_slice(array_values($amounts), 0, 6);
    }

    private function int(string $key, int $default): int
    {
        $value = trim((string) $this->settings->get($key, (string) $default));

        return 1 === preg_match('/^\d{1,12}$/', $value) ? (int) $value : $default;
    }
}
