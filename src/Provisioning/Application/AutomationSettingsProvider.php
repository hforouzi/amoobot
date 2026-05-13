<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Shared\Infrastructure\SettingValueProvider;

final class AutomationSettingsProvider
{
    public function __construct(
        private readonly SettingValueProvider $settingValueProvider,
    ) {
    }

    public function syncUsageEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.sync_usage_enabled', true);
    }

    public function checkExpiryEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.check_expiry_enabled', true);
    }

    public function sendNotificationsEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.send_notifications_enabled', true);
    }

    public function autoSuspendExpiredEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.auto_suspend_expired_enabled', false);
    }

    public function autoSuspendTrafficExhaustedEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.auto_suspend_traffic_exhausted_enabled', false);
    }

    public function batchLimit(): int
    {
        $raw = $this->settingValueProvider->get('automation.batch_limit', '100');
        $limit = is_string($raw) ? (int) trim($raw) : 100;

        if ($limit <= 0) {
            return 100;
        }

        return min($limit, 1000);
    }

    public function expireIncompleteOrdersEnabled(): bool
    {
        return $this->settingValueProvider->getBool('automation.expire_incomplete_orders_enabled', true);
    }
}
