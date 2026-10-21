<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Support\AdminTabs;
use App\Entity\RequiredChannel;
use App\Entity\Setting;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Security\AdminAuditLogger;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One settings page (SETTINGS_MANAGE) with tabs, ?tab=...: sales & renewal pricing, automation, required channels
 * (the RequiredChannel list; create/edit stay on its CRUD pages) and advanced (the raw Setting keys, behind a
 * warning). Each form tab saves only its own keys and validates as the separate pages did; the old pages redirect
 * here (LegacyAdminRoutes, and the RequiredChannel / Setting CRUD index()).
 */
final class SettingsAdminController extends AbstractController
{
    use TranslatedFlashes;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly AutomationSettingsProvider $automationSettingsProvider,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
        private readonly SalesSettingsProvider $salesSettingsProvider,
        private readonly AdminTabs $tabs,
    ) {
    }

    #[AdminRoute(path: '/settings', name: 'settings', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request, AdminAuditLogger $auditLogger): Response
    {
        $tab = (string) $request->query->get('tab', 'sales');
        if (!array_key_exists($tab, AdminTabs::SETTINGS)) {
            $tab = 'sales';
        }

        if ($request->isMethod('POST')) {
            match ($tab) {
                'sales' => $this->saveSales($request, $auditLogger),
                'automation' => $this->saveAutomation($request, $auditLogger),
                // The lists have no form of their own (their rows are edited on the CRUD pages).
                default => null,
            };
        }

        return $this->render('admin/settings/index.html.twig', [
            'tab' => $tab,
            'tabs' => $this->tabs->settings($tab),
        ] + match ($tab) {
            'sales' => $this->salesParameters(),
            'automation' => ['values' => $this->automationValues()],
            'channels' => ['channels' => $this->entityManager->getRepository(RequiredChannel::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC'])],
            'advanced' => ['settings' => $this->entityManager->getRepository(Setting::class)->findBy([], ['keyName' => 'ASC'])],
        });
    }

    /** Was admin_renewal_pricing_settings (POST). */
    private function saveSales(Request $request, AdminAuditLogger $auditLogger): void
    {
        $discountRaw = trim((string) $request->request->get('pricing_global_discount_percent', '0'));
        $discountPercent = is_numeric($discountRaw) ? (float) $discountRaw : -1;
        $trafficMinRaw = trim((string) $request->request->get('traffic_addon_min_gb', '1'));
        $trafficMaxRaw = trim((string) $request->request->get('traffic_addon_max_gb', '100'));
        $trafficPriceRaw = trim((string) $request->request->get('traffic_addon_price_per_gb', '0'));
        $disabledMessage = trim((string) $request->request->get('sales_disabled_message', ''));
        $trafficMinGb = is_numeric($trafficMinRaw) ? (int) floor((float) $trafficMinRaw) : 0;
        $trafficMaxGb = is_numeric($trafficMaxRaw) ? (int) floor((float) $trafficMaxRaw) : 0;
        $trafficPricePerGb = is_numeric($trafficPriceRaw) ? (int) floor((float) $trafficPriceRaw) : -1;

        if ($discountPercent < 0 || $discountPercent > 100) {
            $this->flash('danger', 'flash.settings.discount_range');
        } elseif ('' === $disabledMessage) {
            $this->flash('danger', 'flash.settings.disabled_message_empty');
        } elseif ($trafficMinGb <= 0 || $trafficMaxGb <= 0 || $trafficMaxGb < $trafficMinGb) {
            $this->flash('danger', 'flash.settings.traffic_range');
        } elseif ($trafficPricePerGb < 0) {
            $this->flash('danger', 'flash.settings.traffic_price');
        } else {
            $this->upsertSetting('renewal.carry_remaining_traffic', $request->request->has('renewal_carry_remaining_traffic') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('renewal.carry_remaining_days', $request->request->has('renewal_carry_remaining_days') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('renewal.expired_start_from_now', $request->request->has('renewal_expired_start_from_now') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('pricing.global_discount_percent', (string) floor($discountPercent), 'number');
            $this->upsertSetting('traffic_addon.enabled', $request->request->has('traffic_addon_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('traffic_addon.min_gb', (string) $trafficMinGb, 'number');
            $this->upsertSetting('traffic_addon.max_gb', (string) $trafficMaxGb, 'number');
            $this->upsertSetting('traffic_addon.price_per_gb', (string) $trafficPricePerGb, 'number');
            $this->upsertSetting(SalesSettingsProvider::NEW_ORDERS_ENABLED, $request->request->has('sales_new_orders_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting(SalesSettingsProvider::RENEWALS_ENABLED, $request->request->has('sales_renewals_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, $request->request->has('sales_add_traffic_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting(SalesSettingsProvider::DISABLED_MESSAGE, $disabledMessage, 'text');
            $this->entityManager->flush();
            $auditLogger->record('settings.update', 'Setting', null, ['page' => 'renewal_pricing']);
            $this->flash('success', 'flash.settings.sales_saved');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function salesParameters(): array
    {
        return [
            'values' => [
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
            ],
            'keys' => [
                'sales_new_orders_enabled' => SalesSettingsProvider::NEW_ORDERS_ENABLED,
                'sales_renewals_enabled' => SalesSettingsProvider::RENEWALS_ENABLED,
                'sales_add_traffic_enabled' => SalesSettingsProvider::ADD_TRAFFIC_ENABLED,
                'sales_disabled_message' => SalesSettingsProvider::DISABLED_MESSAGE,
            ],
        ];
    }

    /** Was admin_automation_settings (POST). */
    private function saveAutomation(Request $request, AdminAuditLogger $auditLogger): void
    {
        $batchLimitRaw = trim((string) $request->request->get('automation_batch_limit', '100'));
        $expireHoursRaw = trim((string) $request->request->get('orders_incomplete_expire_hours', '24'));
        $batchLimit = is_numeric($batchLimitRaw) ? (int) $batchLimitRaw : 0;
        $expireHours = is_numeric($expireHoursRaw) ? (int) $expireHoursRaw : 0;
        if ($batchLimit <= 0) {
            $this->flash('danger', 'flash.settings.batch_limit');
        } elseif ($expireHours <= 0) {
            $this->flash('danger', 'flash.settings.expire_hours');
        } else {
            $this->upsertSetting('automation.sync_usage_enabled', $request->request->has('automation_sync_usage_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.check_expiry_enabled', $request->request->has('automation_check_expiry_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.send_notifications_enabled', $request->request->has('automation_send_notifications_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.auto_suspend_expired_enabled', $request->request->has('automation_auto_suspend_expired_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.auto_suspend_traffic_exhausted_enabled', $request->request->has('automation_auto_suspend_traffic_exhausted_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.expire_incomplete_orders_enabled', $request->request->has('automation_expire_incomplete_orders_enabled') ? 'true' : 'false', 'boolean');
            $this->upsertSetting('automation.batch_limit', (string) $batchLimit, 'number');
            $this->upsertSetting('orders.incomplete_expire_hours', (string) $expireHours, 'number');
            $this->entityManager->flush();
            $auditLogger->record('settings.update', 'Setting', null, ['page' => 'automation']);
            $this->flash('success', 'flash.settings.automation_saved');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function automationValues(): array
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

    private function upsertSetting(string $keyName, string $value, string $type): void
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
}
