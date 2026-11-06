<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

use App\Shared\Infrastructure\SettingValueProvider;

/**
 * The switch of app:payments:winapay-reconcile, a Setting row edited in Admin > Settings (app:settings:seed adds it,
 * as false). Off by default and fail-closed: a missing, empty or unknown value is off. While off, --apply verifies and
 * changes nothing. Read once per run, so it is not remembered.
 */
final class WinapayReconcileSettings
{
    public const ENABLED = 'payments.winapay_reconcile_enabled';

    public function __construct(private readonly SettingValueProvider $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::ENABLED, false);
    }
}
