<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\Setting;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Reseller\Application\ResellerAdministration;
use App\Reseller\Application\ResellerSettings;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\SalesSettingsProvider;
use App\Wallet\Application\WalletSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The settings page's forms (SETTINGS_MANAGE), for the web settings tabs and the Mini App settings: "sales &
 * pricing" (sales switches, the sales-closed message, renewal pricing, traffic add-on), "automation" (automation
 * switches, batch limit, incomplete-order expiry), "wallet" (wallet switches, top-up range and quick amounts) and "resellers"
 * (the reseller switch, the wholesale floor and rounding). Only these keys are read or written here; nothing secret is among
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

    /** The wallet form's fields and the setting each one writes. */
    public const WALLET_KEYS = [
        'wallet_enabled' => WalletSettings::ENABLED,
        'wallet_payment_enabled' => WalletSettings::PAYMENT_ENABLED,
        'wallet_topup_min' => WalletSettings::TOPUP_MIN,
        'wallet_topup_max' => WalletSettings::TOPUP_MAX,
        'wallet_topup_quick_amounts' => WalletSettings::QUICK_AMOUNTS,
    ];

    /** The resellers form's fields and the setting each one writes. */
    public const RESELLER_KEYS = [
        'reseller_enabled' => ResellerSettings::ENABLED,
        'reseller_floor_percent' => ResellerSettings::FLOOR_BP,
        'reseller_rounding' => ResellerSettings::ROUNDING,
        'reseller_claims_enabled' => ResellerSettings::CLAIMS_ENABLED,
        'reseller_claim_ttl_hours' => ResellerSettings::CLAIM_TTL_HOURS,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly AutomationSettingsProvider $automationSettingsProvider,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
        private readonly SalesSettingsProvider $salesSettingsProvider,
        private readonly WalletSettings $walletSettings,
        private readonly ResellerSettings $resellerSettings,
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
     * The sales form's first problem, in the web page's order, or null when it can be saved.
     *
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null
     */
    public function salesProblem(array $form): ?array
    {
        [$discountPercent, $trafficMinGb, $trafficMaxGb, $trafficPricePerGb, $disabledMessage] = self::sales($form);

        return match (true) {
            $discountPercent < 0 || $discountPercent > 100 => ['field' => 'pricing_global_discount_percent', 'message' => 'flash.settings.discount_range'],
            '' === $disabledMessage => ['field' => 'sales_disabled_message', 'message' => 'flash.settings.disabled_message_empty'],
            $trafficMinGb <= 0 || $trafficMaxGb <= 0 || $trafficMaxGb < $trafficMinGb => ['field' => $trafficMinGb <= 0 ? 'traffic_addon_min_gb' : 'traffic_addon_max_gb', 'message' => 'flash.settings.traffic_range'],
            $trafficPricePerGb < 0 => ['field' => 'traffic_addon_price_per_gb', 'message' => 'flash.settings.traffic_price'],
            default => null,
        };
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
        $problem = $this->salesProblem($form);
        if (null !== $problem) {
            return $problem;
        }
        [$discountPercent, $trafficMinGb, $trafficMaxGb, $trafficPricePerGb, $disabledMessage] = self::sales($form);

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
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null
     */
    public function automationProblem(array $form): ?array
    {
        [$batchLimit, $expireHours] = self::automation($form);

        return match (true) {
            $batchLimit <= 0 => ['field' => 'automation_batch_limit', 'message' => 'flash.settings.batch_limit'],
            $expireHours <= 0 => ['field' => 'orders_incomplete_expire_hours', 'message' => 'flash.settings.expire_hours'],
            default => null,
        };
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
        $problem = $this->automationProblem($form);
        if (null !== $problem) {
            return $problem;
        }
        [$batchLimit, $expireHours] = self::automation($form);

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

    /**
     * @return array<string, bool|int|string>
     */
    public function walletValues(): array
    {
        return [
            'wallet_enabled' => $this->walletSettings->enabled(),
            'wallet_payment_enabled' => $this->walletSettings->paymentSwitch(),
            'wallet_topup_min' => $this->walletSettings->topUpMin(),
            'wallet_topup_max' => $this->walletSettings->topUpMax(),
            'wallet_topup_quick_amounts' => $this->walletSettings->quickAmountsText(),
        ];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null
     */
    public function walletProblem(array $form): ?array
    {
        [$min, $max, $quick] = self::wallet($form);

        return match (true) {
            $min < 1 => ['field' => 'wallet_topup_min', 'message' => 'flash.settings.wallet_range'],
            $max < $min => ['field' => 'wallet_topup_max', 'message' => 'flash.settings.wallet_range'],
            null === $quick || [] !== array_filter($quick, static fn (int $amount): bool => $amount < $min || $amount > $max) => ['field' => 'wallet_topup_quick_amounts', 'message' => 'flash.settings.wallet_quick_amounts'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null the first problem (nothing saved), or null: saved and flushed
     */
    public function saveWallet(array $form): ?array
    {
        $problem = $this->walletProblem($form);
        if (null !== $problem) {
            return $problem;
        }
        [$min, $max, $quick] = self::wallet($form);

        $this->set(WalletSettings::ENABLED, self::checked($form, 'wallet_enabled'), 'boolean');
        $this->set(WalletSettings::PAYMENT_ENABLED, self::checked($form, 'wallet_payment_enabled'), 'boolean');
        $this->set(WalletSettings::TOPUP_MIN, (string) $min, 'number');
        $this->set(WalletSettings::TOPUP_MAX, (string) $max, 'number');
        $this->set(WalletSettings::QUICK_AMOUNTS, implode(',', $quick ?? []), 'text');
        $this->entityManager->flush();

        return null;
    }

    /**
     * The resellers form's current values: the floor as a percent of the base price (two decimals at most).
     *
     * @return array<string, bool|int|string>
     */
    public function resellerValues(): array
    {
        return [
            'reseller_enabled' => $this->resellerSettings->enabled(),
            'reseller_floor_percent' => ResellerAdministration::bpToPercent($this->resellerSettings->floorBp()),
            'reseller_rounding' => $this->resellerSettings->rounding(),
            'reseller_claims_enabled' => $this->resellerSettings->settingClaimsOn(),
            'reseller_claim_ttl_hours' => $this->resellerSettings->claimTtlHours(),
        ];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null
     */
    public function resellerProblem(array $form): ?array
    {
        $rounding = self::text($form, 'reseller_rounding', '');
        $ttl = self::text($form, 'reseller_claim_ttl_hours', (string) ResellerSettings::DEFAULT_CLAIM_TTL_HOURS);

        return match (true) {
            null === ResellerAdministration::percentToBp(self::text($form, 'reseller_floor_percent', ''), 10000) => ['field' => 'reseller_floor_percent', 'message' => 'flash.settings.reseller_floor'],
            1 !== preg_match('/^\d{1,7}$/D', $rounding) || (int) $rounding < 1 || (int) $rounding > ResellerSettings::MAX_ROUNDING => ['field' => 'reseller_rounding', 'message' => 'flash.settings.reseller_rounding'],
            1 !== preg_match('/^\d{1,4}$/D', $ttl) || (int) $ttl < 1 || (int) $ttl > ResellerSettings::MAX_CLAIM_TTL_HOURS => ['field' => 'reseller_claim_ttl_hours', 'message' => 'flash.settings.reseller_claim_ttl'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{field: string, message: string}|null the first problem (nothing saved), or null: saved and flushed
     */
    public function saveReseller(array $form): ?array
    {
        $problem = $this->resellerProblem($form);
        if (null !== $problem) {
            return $problem;
        }

        $this->set(ResellerSettings::ENABLED, self::checked($form, 'reseller_enabled'), 'boolean');
        $this->set(ResellerSettings::FLOOR_BP, (string) ResellerAdministration::percentToBp(self::text($form, 'reseller_floor_percent', ''), 10000), 'number');
        $this->set(ResellerSettings::ROUNDING, (string) (int) self::text($form, 'reseller_rounding', ''), 'number');
        $this->set(ResellerSettings::CLAIMS_ENABLED, self::checked($form, 'reseller_claims_enabled'), 'boolean');
        $this->set(ResellerSettings::CLAIM_TTL_HOURS, (string) (int) self::text($form, 'reseller_claim_ttl_hours', (string) ResellerSettings::DEFAULT_CLAIM_TTL_HOURS), 'number');
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
     * The sales form read as the web page reads it: decimals floored, anything unreadable out of range.
     *
     * @param array<string, mixed> $form
     *
     * @return array{float, int, int, int, string}
     */
    private static function sales(array $form): array
    {
        $discountRaw = self::text($form, 'pricing_global_discount_percent', '0');
        $trafficMinRaw = self::text($form, 'traffic_addon_min_gb', '1');
        $trafficMaxRaw = self::text($form, 'traffic_addon_max_gb', '100');
        $trafficPriceRaw = self::text($form, 'traffic_addon_price_per_gb', '0');

        return [
            is_numeric($discountRaw) ? (float) $discountRaw : -1,
            is_numeric($trafficMinRaw) ? (int) floor((float) $trafficMinRaw) : 0,
            is_numeric($trafficMaxRaw) ? (int) floor((float) $trafficMaxRaw) : 0,
            is_numeric($trafficPriceRaw) ? (int) floor((float) $trafficPriceRaw) : -1,
            self::text($form, 'sales_disabled_message', ''),
        ];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array{int, int}
     */
    private static function automation(array $form): array
    {
        $batchLimitRaw = self::text($form, 'automation_batch_limit', '100');
        $expireHoursRaw = self::text($form, 'orders_incomplete_expire_hours', '24');

        return [is_numeric($batchLimitRaw) ? (int) $batchLimitRaw : 0, is_numeric($expireHoursRaw) ? (int) $expireHoursRaw : 0];
    }

    /**
     * The wallet form: integer Toman; quick amounts a comma-separated list (null when one is not a whole number).
     *
     * @param array<string, mixed> $form
     *
     * @return array{int, int, list<int>|null}
     */
    private static function wallet(array $form): array
    {
        $min = self::text($form, 'wallet_topup_min', '');
        $max = self::text($form, 'wallet_topup_max', '');
        $quick = [];
        foreach (explode(',', str_replace(['،', ' '], [',', ''], self::text($form, 'wallet_topup_quick_amounts', ''))) as $part) {
            if ('' === $part) {
                continue;
            }
            if (1 !== preg_match('/^\d{1,12}$/', $part)) {
                $quick = null;
                break;
            }
            $quick[] = (int) $part;
        }

        return [
            1 === preg_match('/^\d{1,12}$/', $min) ? (int) $min : 0,
            1 === preg_match('/^\d{1,12}$/', $max) ? (int) $max : -1,
            null === $quick ? null : array_values(array_unique($quick)),
        ];
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
