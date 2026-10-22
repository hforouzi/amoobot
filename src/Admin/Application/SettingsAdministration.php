<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\Setting;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The settings page's two forms (SETTINGS_MANAGE), for the web settings tabs and the Mini App settings: "sales &
 * pricing" (sales switches, the sales-closed message, renewal pricing, traffic add-on) and "automation" (automation
 * switches, batch limit, incomplete-order expiry). Only these keys are read or written here; nothing secret is among
 * them. Input is a form's fields by the web form's names, a checkbox being on when its field is present.
 * A refused form saves nothing and names the first problem: the field and its message key (admin domain), in the
 * order the web page checks them. The caller audits.
 */
final class SettingsAdministration
{
    /** The sales form's fields and the setting each one writes. */
    public const SALES_KEYS = [
        'renewal_carry_remaining_traffic' => 'renewal.carry_remaining_traffic',
        'renewal_carry_remaining_days' => 'renewal.carry_remaining_days',
        'renewal_expired_start_from_now' => 'renewal.expired_start_from_now',
        'pricing_global_discount_percent' => 'pricing.global_discount_percent',
        'traffic_addon_enabled' => 'traffic_addon.enabled',
        'traffic_addon_min_gb' => 'traffic_addon.min_gb',
        'traffic_addon_max_gb' => 'traffic_addon.max_gb',
        'traffic_addon_price_per_gb' => 'traffic_addon.price_per_gb',
        'sales_new_orders_enabled' => SalesSettingsProvider::NEW_ORDERS_ENABLED,
        'sales_renewals_enabled' => SalesSettingsProvider::RENEWALS_ENABLED,
        'sales_add_traffic_enabled' => SalesSettingsProvider::ADD_TRAFFIC_ENABLED,
        'sales_disabled_message' => SalesSettingsProvider::DISABLED_MESSAGE,
    ];

    /** The automation form's fields and the setting each one writes. */
    public const AUTOMATION_KEYS = [
        'automation_sync_usage_enabled' => 'automation.sync_usage_enabled',
        'automation_check_expiry_enabled' => 'automation.check_expiry_enabled',
        'automation_send_notifications_enabled' => 'automation.send_notifications_enabled',
        'automation_auto_suspend_expired_enabled' => 'automation.auto_suspend_expired_enabled',
        'automation_auto_suspend_traffic_exhausted_enabled' => 'automation.auto_suspend_traffic_exhausted_enabled',
        'automation_expire_incomplete_orders_enabled' => 'automation.expire_incomplete_orders_enabled',
        'automation_batch_limit' => 'automation.batch_limit',
        'orders_incomplete_expire_hours' => 'orders.incomplete_expire_hours',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly AutomationSettingsProvider $automationSettingsProvider,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
        private readonly SalesSettingsProvider $salesSettingsProvider,
    ) {
    }

    /**
     * The sales form's current values (the effective ones, defaults included).
     *
     * @return array<string, bool|int|float|string>
     */
    public function salesValues(): array
    {
        return [
            'renewal_carry_remaining_traffic' => $this->renewalSettingsProvider->carryRemainingTraffic(),
            'renewal_carry_remaining_days' => $this->renewalSettingsProvider->carryRemainingDays(),
            'renewal_expired_start_from_now' => $this->renewalSettingsProvider->expiredStartFromNow(),
            'pricing_global_discount_percent' => $this->renewalSettingsProvider->globalDiscountPercent(),
            'traffic_addon_enabled' => $this->trafficAddonSettingsProvider->enabled(),
            'traffic_addon_min_gb' => $this->trafficAddonSettingsProvider->minGb(),
            'traffic_addon_max_gb' => $this->trafficAddonSettingsProvider->maxGb(),
            'traffic_addon_price_per_gb' => $this->trafficAddonSettingsProvider->pricePerGb(),
            'sales_new_orders_enabled' => $this->salesSettingsProvider->newOrdersEnabled(),
            'sales_renewals_enabled' => $this->salesSettingsProvider->renewalsEnabled(),
            'sales_add_traffic_enabled' => $this->salesSettingsProvider->addTrafficEnabled(),
            'sales_disabled_message' => $this->salesSettingsProvider->disabledMessage(),
        ];
    }

    /**
     * @return array<string, bool|int>
     */
    public function automationValues(): array
    {
        return [
            'automation_sync_usage_enabled' => $this->automationSettingsProvider->syncUsageEnabled(),
            'automation_check_expiry_enabled' => $this->automationSettingsProvider->checkExpiryEnabled(),
            'automation_send_notifications_enabled' => $this->automationSettingsProvider->sendNotificationsEnabled(),
            'automation_auto_suspend_expired_enabled' => $this->automationSettingsProvider->autoSuspendExpiredEnabled(),
            'automation_auto_suspend_traffic_exhausted_enabled' => $this->automationSettingsProvider->autoSuspendTrafficExhaustedEnabled(),
            'automation_expire_incomplete_orders_enabled' => $this->automationSettingsProvider->expireIncompleteOrdersEnabled(),
            'automation_batch_limit' => $this->automationSettingsProvider->batchLimit(),
            'orders_incomplete_expire_hours' => $this->incompleteOrderSettingsProvider->expireHours(),
        ];
    }

    /**
     * Was SettingsAdminController::saveSales().
     *
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null the first problem (nothing saved), or null: saved and flushed
     */
    public function saveSales(array $form): ?array
    {
        $discountRaw = self::text($form, 'pricing_global_discount_percent', '0');
        $discountPercent = is_numeric($discountRaw) ? (float) $discountRaw : -1;
        $trafficMinRaw = self::text($form, 'traffic_addon_min_gb', '1');
        $trafficMaxRaw = self::text($form, 'traffic_addon_max_gb', '100');
        $trafficPriceRaw = self::text($form, 'traffic_addon_price_per_gb', '0');
        $disabledMessage = self::text($form, 'sales_disabled_message', '');
        $trafficMinGb = is_numeric($trafficMinRaw) ? (int) floor((float) $trafficMinRaw) : 0;
        $trafficMaxGb = is_numeric($trafficMaxRaw) ? (int) floor((float) $trafficMaxRaw) : 0;
        $trafficPricePerGb = is_numeric($trafficPriceRaw) ? (int) floor((float) $trafficPriceRaw) : -1;

        $problem = match (true) {
            $discountPercent < 0 || $discountPercent > 100 => ['field' => 'pricing_global_discount_percent', 'message' => 'flash.settings.discount_range'],
            '' === $disabledMessage => ['field' => 'sales_disabled_message', 'message' => 'flash.settings.disabled_message_empty'],
            $trafficMinGb <= 0 || $trafficMaxGb <= 0 || $trafficMaxGb < $trafficMinGb => ['field' => $trafficMinGb <= 0 ? 'traffic_addon_min_gb' : 'traffic_addon_max_gb', 'message' => 'flash.settings.traffic_range'],
            $trafficPricePerGb < 0 => ['field' => 'traffic_addon_price_per_gb', 'message' => 'flash.settings.traffic_price'],
            default => null,
        };
        if (null !== $problem) {
            return $problem;
        }

        $this->set('renewal.carry_remaining_traffic', self::checked($form, 'renewal_carry_remaining_traffic'), 'boolean');
        $this->set('renewal.carry_remaining_days', self::checked($form, 'renewal_carry_remaining_days'), 'boolean');
        $this->set('renewal.expired_start_from_now', self::checked($form, 'renewal_expired_start_from_now'), 'boolean');
        $this->set('pricing.global_discount_percent', (string) floor($discountPercent), 'number');
        $this->set('traffic_addon.enabled', self::checked($form, 'traffic_addon_enabled'), 'boolean');
        $this->set('traffic_addon.min_gb', (string) $trafficMinGb, 'number');
        $this->set('traffic_addon.max_gb', (string) $trafficMaxGb, 'number');
        $this->set('traffic_addon.price_per_gb', (string) $trafficPricePerGb, 'number');
        $this->set(SalesSettingsProvider::NEW_ORDERS_ENABLED, self::checked($form, 'sales_new_orders_enabled'), 'boolean');
        $this->set(SalesSettingsProvider::RENEWALS_ENABLED, self::checked($form, 'sales_renewals_enabled'), 'boolean');
        $this->set(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, self::checked($form, 'sales_add_traffic_enabled'), 'boolean');
        $this->set(SalesSettingsProvider::DISABLED_MESSAGE, $disabledMessage, 'text');
        $this->entityManager->flush();

        return null;
    }

    /**
     * Was SettingsAdminController::saveAutomation().
     *
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null
     */
    public function saveAutomation(array $form): ?array
    {
        $batchLimitRaw = self::text($form, 'automation_batch_limit', '100');
        $expireHoursRaw = self::text($form, 'orders_incomplete_expire_hours', '24');
        $batchLimit = is_numeric($batchLimitRaw) ? (int) $batchLimitRaw : 0;
        $expireHours = is_numeric($expireHoursRaw) ? (int) $expireHoursRaw : 0;
        if ($batchLimit <= 0) {
            return ['field' => 'automation_batch_limit', 'message' => 'flash.settings.batch_limit'];
        }
        if ($expireHours <= 0) {
            return ['field' => 'orders_incomplete_expire_hours', 'message' => 'flash.settings.expire_hours'];
        }

        $this->set('automation.sync_usage_enabled', self::checked($form, 'automation_sync_usage_enabled'), 'boolean');
        $this->set('automation.check_expiry_enabled', self::checked($form, 'automation_check_expiry_enabled'), 'boolean');
        $this->set('automation.send_notifications_enabled', self::checked($form, 'automation_send_notifications_enabled'), 'boolean');
        $this->set('automation.auto_suspend_expired_enabled', self::checked($form, 'automation_auto_suspend_expired_enabled'), 'boolean');
        $this->set('automation.auto_suspend_traffic_exhausted_enabled', self::checked($form, 'automation_auto_suspend_traffic_exhausted_enabled'), 'boolean');
        $this->set('automation.expire_incomplete_orders_enabled', self::checked($form, 'automation_expire_incomplete_orders_enabled'), 'boolean');
        $this->set('automation.batch_limit', (string) $batchLimit, 'number');
        $this->set('orders.incomplete_expire_hours', (string) $expireHours, 'number');
        $this->entityManager->flush();

        return null;
    }

    /** Creates or updates one setting (the caller flushes). */
    public function set(string $keyName, string $value, string $type): void
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $keyName]);
        if (!$setting instanceof Setting) {
            $setting = (new Setting())->setKeyName($keyName);
            $this->entityManager->persist($setting);
        }

        $setting
            ->setValue($value)
            ->setType($type)
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function text(array $form, string $field, string $default): string
    {
        $value = $form[$field] ?? $default;

        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function checked(array $form, string $field): string
    {
        return array_key_exists($field, $form) ? 'true' : 'false';
    }
}
