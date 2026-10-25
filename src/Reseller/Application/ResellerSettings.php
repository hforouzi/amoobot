<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The reseller switches (Setting rows, edited on the web settings page, "resellers" tab, SETTINGS_MANAGE). Off by
 * default: with reseller.enabled off no reseller menu shows and no wholesale purchase can start, for anybody.
 *
 * Claim links (S2) have their own switch on top, reseller.claims_enabled, also off by default: claimsEnabled() is true
 * only with both on. With it off no link can be made or used, /start claim_... is a plain /start and nothing changes
 * for any customer.
 */
class ResellerSettings
{
    public const ENABLED = 'reseller.enabled';
    /** The lowest wholesale price as a share of the base price, in basis points (5000 = 50 %). */
    public const FLOOR_BP = 'reseller.floor_bp';
    /** Wholesale prices are rounded UP to a multiple of this many Toman (before the floor is checked). */
    public const ROUNDING = 'reseller.rounding';
    /** Claim links (S2): resellers and admins hand a service over to a Telegram user. Needs ENABLED too. */
    public const CLAIMS_ENABLED = 'reseller.claims_enabled';
    /** How long a new claim link stays usable, in hours (1..MAX_CLAIM_TTL_HOURS). */
    public const CLAIM_TTL_HOURS = 'reseller.claim_ttl_hours';

    public const DEFAULT_FLOOR_BP = 5000;
    public const DEFAULT_ROUNDING = 1000;
    public const MAX_ROUNDING = 1_000_000;
    /** 7 days. */
    public const DEFAULT_CLAIM_TTL_HOURS = 168;
    /** 30 days. */
    public const MAX_CLAIM_TTL_HOURS = 720;

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }

    /** Claim links may be made and used: resellers on and claim links on. */
    public function claimsEnabled(): bool
    {
        return $this->enabled() && $this->settings->getBool(self::CLAIMS_ENABLED, false);
    }

    /** The claim-links switch alone (the settings form shows it as saved, whatever reseller.enabled is). */
    public function settingClaimsOn(): bool
    {
        return $this->settings->getBool(self::CLAIMS_ENABLED, false);
    }

    public function claimTtlHours(): int
    {
        return max(1, min(self::MAX_CLAIM_TTL_HOURS, $this->int(self::CLAIM_TTL_HOURS, self::DEFAULT_CLAIM_TTL_HOURS)));
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
