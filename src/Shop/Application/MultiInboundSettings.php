<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The switch for plans with several inbounds (docs/design/multi-inbound-plans.md 6), a Setting row edited on the web
 * settings page, "plans" tab (SETTINGS_MANAGE). Off by default. While off the web plan form has no "additional inbounds"
 * field and refuses one sent anyway, the plan list and the inbound page look as before.
 *
 * Phase M1: nothing sells differently with it on either. A plan with additional inbounds is never sold until
 * provisioning attaches them (M2; Plan::isAvailableForSale()), whatever this switch says.
 *
 * Read on admin pages only, so it is not remembered between calls.
 */
final class MultiInboundSettings
{
    public const ENABLED = 'plans.multi_inbound_enabled';

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }
}
