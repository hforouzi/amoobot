<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Shared\Infrastructure\SettingValueProvider;

final class RenewalSettingsProvider
{
    public function __construct(
        private readonly SettingValueProvider $settingValueProvider,
        private readonly string $renewalCarryRemainingTraffic = 'true',
        private readonly string $renewalCarryRemainingDays = 'true',
        private readonly string $renewalExpiredStartFromNow = 'true',
        private readonly string $pricingGlobalDiscountPercent = '0',
    ) {
    }

    public function carryRemainingTraffic(): bool
    {
        return $this->settingValueProvider->getBool('renewal.carry_remaining_traffic', $this->toBool($this->renewalCarryRemainingTraffic));
    }

    public function carryRemainingDays(): bool
    {
        return $this->settingValueProvider->getBool('renewal.carry_remaining_days', $this->toBool($this->renewalCarryRemainingDays));
    }

    public function expiredStartFromNow(): bool
    {
        return $this->settingValueProvider->getBool('renewal.expired_start_from_now', $this->toBool($this->renewalExpiredStartFromNow));
    }

    public function globalDiscountPercent(): float
    {
        $value = $this->settingValueProvider->get('pricing.global_discount_percent', $this->pricingGlobalDiscountPercent);
        $percent = is_string($value) ? (float) trim($value) : 0.0;
        if ($percent < 0) {
            return 0.0;
        }
        if ($percent > 100) {
            return 100.0;
        }

        return $percent;
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
