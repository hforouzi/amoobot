<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The reseller switches (Setting rows, edited on the web settings page, "resellers" tab, SETTINGS_MANAGE). Off by
 * default: with reseller.enabled off no reseller menu shows and no wholesale purchase can start, for anybody.
 */
class ResellerSettings
{
    public const ENABLED = 'reseller.enabled';
    /** The lowest wholesale price as a share of the base price, in basis points (5000 = 50 %). */
    public const FLOOR_BP = 'reseller.floor_bp';
    /** Wholesale prices are rounded UP to a multiple of this many Toman (before the floor is checked). */
    public const ROUNDING = 'reseller.rounding';

    public const DEFAULT_FLOOR_BP = 5000;
    public const DEFAULT_ROUNDING = 1000;
    public const MAX_ROUNDING = 1_000_000;

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }

    public function floorBp(): int
    {
        return min(10000, $this->int(self::FLOOR_BP, self::DEFAULT_FLOOR_BP));
    }

    public function rounding(): int
    {
        return max(1, min(self::MAX_ROUNDING, $this->int(self::ROUNDING, self::DEFAULT_ROUNDING)));
    }

    private function int(string $key, int $default): int
    {
        $value = trim((string) $this->settings->get($key, (string) $default));

        return 1 === preg_match('/^\d{1,12}$/D', $value) ? (int) $value : $default;
    }
}
