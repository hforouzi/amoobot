<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Shared\Infrastructure\SettingValueProvider;

final class TrafficAddonSettingsProvider
{
    public function __construct(
        private readonly SettingValueProvider $settingValueProvider,
        private readonly string $trafficAddonEnabled = 'true',
        private readonly string $trafficAddonMinGb = '1',
        private readonly string $trafficAddonMaxGb = '100',
        private readonly string $trafficAddonPricePerGb = '0',
    ) {
    }

    public function enabled(): bool
    {
        return $this->settingValueProvider->getBool('traffic_addon.enabled', $this->toBool($this->trafficAddonEnabled));
    }

    public function minGb(): int
    {
        $value = $this->toInt($this->settingValueProvider->get('traffic_addon.min_gb', $this->trafficAddonMinGb));

        return max(1, $value);
    }

    public function maxGb(): int
    {
        $value = $this->toInt($this->settingValueProvider->get('traffic_addon.max_gb', $this->trafficAddonMaxGb));

        return max($this->minGb(), $value);
    }

    public function pricePerGb(): int
    {
        $value = $this->toInt($this->settingValueProvider->get('traffic_addon.price_per_gb', $this->trafficAddonPricePerGb));

        return max(0, $value);
    }

    public function canPurchase(): bool
    {
        return $this->enabled() && $this->pricePerGb() > 0;
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function toInt(?string $value): int
    {
        if (!is_string($value) || '' === trim($value) || !is_numeric($value)) {
            return 0;
        }

        return (int) floor((float) $value);
    }
}
