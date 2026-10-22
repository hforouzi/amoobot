<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The settings forms' inputs and what the web settings page (SettingsAdminController) answers to them: an error (its
 * flash key, admin domain) and nothing saved, or the values stored. Shared by the web characterization and the
 * Mini App parity test, so both are held to one list. Field names are the web form's; a checkbox is "on" when
 * present, as a browser sends it (null removes a field: an unticked checkbox, or a field left out).
 */
final class SettingsFormCases
{
    public const SALES = [
        'renewal_carry_remaining_traffic' => '1',
        'renewal_carry_remaining_days' => '1',
        'renewal_expired_start_from_now' => '1',
        'pricing_global_discount_percent' => '0',
        'traffic_addon_enabled' => '1',
        'traffic_addon_min_gb' => '1',
        'traffic_addon_max_gb' => '100',
        'traffic_addon_price_per_gb' => '3000',
        'sales_new_orders_enabled' => '1',
        'sales_renewals_enabled' => '1',
        'sales_add_traffic_enabled' => '1',
        'sales_disabled_message' => 'فروش موقتاً بسته است.',
    ];

    public const AUTOMATION = [
        'automation_sync_usage_enabled' => '1',
        'automation_check_expiry_enabled' => '1',
        'automation_send_notifications_enabled' => '1',
        'automation_auto_suspend_expired_enabled' => '1',
        'automation_auto_suspend_traffic_exhausted_enabled' => '1',
        'automation_expire_incomplete_orders_enabled' => '1',
        'automation_batch_limit' => '100',
        'orders_incomplete_expire_hours' => '24',
    ];

    /**
     * @return iterable<string, array{string, array<string, string|null>, string|null, array<string, string>}>
     *                                                                                                          tab, changes to its default input, the web's error flash key (null: saved), stored values (key => value)
     */
    public static function cases(): iterable
    {
        yield 'sales: discount above 100' => ['sales', ['pricing_global_discount_percent' => '150'], 'flash.settings.discount_range', []];
        yield 'sales: negative discount' => ['sales', ['pricing_global_discount_percent' => '-1'], 'flash.settings.discount_range', []];
        yield 'sales: discount not a number' => ['sales', ['pricing_global_discount_percent' => 'ten'], 'flash.settings.discount_range', []];
        yield 'sales: empty closed message' => ['sales', ['sales_disabled_message' => '   '], 'flash.settings.disabled_message_empty', []];
        yield 'sales: add-on minimum 0' => ['sales', ['traffic_addon_min_gb' => '0'], 'flash.settings.traffic_range', []];
        yield 'sales: add-on maximum below minimum' => ['sales', ['traffic_addon_min_gb' => '20', 'traffic_addon_max_gb' => '10'], 'flash.settings.traffic_range', []];
        yield 'sales: negative add-on price' => ['sales', ['traffic_addon_price_per_gb' => '-5'], 'flash.settings.traffic_price', []];
        yield 'sales: add-on price not a number' => ['sales', ['traffic_addon_price_per_gb' => 'free'], 'flash.settings.traffic_price', []];
        yield 'sales: saved, decimals floored, unticked switches off' => ['sales', [
            'pricing_global_discount_percent' => '12.7',
            'traffic_addon_min_gb' => '2.9',
            'traffic_addon_price_per_gb' => '2500.5',
            'sales_renewals_enabled' => null,
            'renewal_carry_remaining_days' => null,
            'sales_disabled_message' => '  بسته است  ',
        ], null, [
            'pricing.global_discount_percent' => '12',
            'traffic_addon.min_gb' => '2',
            'traffic_addon.max_gb' => '100',
            'traffic_addon.price_per_gb' => '2500',
            'sales.renewals_enabled' => 'false',
            'sales.new_orders_enabled' => 'true',
            'renewal.carry_remaining_days' => 'false',
            'renewal.carry_remaining_traffic' => 'true',
            'sales.disabled_message' => 'بسته است',
        ]];
        yield 'automation: batch limit 0' => ['automation', ['automation_batch_limit' => '0'], 'flash.settings.batch_limit', []];
        yield 'automation: batch limit not a number' => ['automation', ['automation_batch_limit' => 'many'], 'flash.settings.batch_limit', []];
        yield 'automation: expire hours 0' => ['automation', ['orders_incomplete_expire_hours' => '0'], 'flash.settings.expire_hours', []];
        yield 'automation: saved, unticked switches off' => ['automation', [
            'automation_batch_limit' => '37',
            'orders_incomplete_expire_hours' => '48',
            'automation_sync_usage_enabled' => null,
        ], null, [
            'automation.batch_limit' => '37',
            'orders.incomplete_expire_hours' => '48',
            'automation.sync_usage_enabled' => 'false',
            'automation.check_expiry_enabled' => 'true',
        ]];
    }

    /**
     * @param array<string, string|null> $changes
     *
     * @return array<string, string>
     */
    public static function input(string $tab, array $changes): array
    {
        $input = array_merge('sales' === $tab ? self::SALES : self::AUTOMATION, $changes);

        return array_filter($input, static fn (?string $value): bool => null !== $value);
    }
}
