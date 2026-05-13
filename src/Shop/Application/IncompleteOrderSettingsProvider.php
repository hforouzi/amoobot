<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shared\Infrastructure\SettingValueProvider;

final class IncompleteOrderSettingsProvider
{
    private const DEFAULT_EXPIRE_HOURS = 24;

    public function __construct(
        private readonly SettingValueProvider $settingValueProvider,
    ) {
    }

    public function expireHours(): int
    {
        $raw = $this->settingValueProvider->get('orders.incomplete_expire_hours', (string) self::DEFAULT_EXPIRE_HOURS);
        $hours = is_string($raw) ? (int) trim($raw) : self::DEFAULT_EXPIRE_HOURS;

        return $hours > 0 ? $hours : self::DEFAULT_EXPIRE_HOURS;
    }
}
