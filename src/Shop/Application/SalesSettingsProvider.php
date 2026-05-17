<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shared\Infrastructure\SettingValueProvider;

final class SalesSettingsProvider
{
    public const NEW_ORDERS_ENABLED = 'sales.new_orders_enabled';
    public const RENEWALS_ENABLED = 'sales.renewals_enabled';
    public const ADD_TRAFFIC_ENABLED = 'sales.add_traffic_enabled';
    public const DISABLED_MESSAGE = 'sales.disabled_message';
    public const DEFAULT_DISABLED_MESSAGE = 'در حال حاضر فروش غیرفعال است. لطفاً بعداً دوباره تلاش کنید.';

    public function __construct(private readonly SettingValueProvider $settingValueProvider)
    {
    }

    public function newOrdersEnabled(): bool
    {
        return $this->settingValueProvider->getBool(self::NEW_ORDERS_ENABLED, true);
    }

    public function renewalsEnabled(): bool
    {
        return $this->settingValueProvider->getBool(self::RENEWALS_ENABLED, true);
    }

    public function addTrafficEnabled(): bool
    {
        return $this->settingValueProvider->getBool(self::ADD_TRAFFIC_ENABLED, true);
    }

    public function disabledMessage(): string
    {
        return (string) $this->settingValueProvider->get(self::DISABLED_MESSAGE, self::DEFAULT_DISABLED_MESSAGE);
    }
}
