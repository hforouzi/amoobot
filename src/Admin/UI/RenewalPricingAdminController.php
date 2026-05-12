<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\Setting;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\PlanPriceAdjustmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class RenewalPricingAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RenewalSettingsProvider $renewalSettingsProvider,
        private readonly AutomationSettingsProvider $automationSettingsProvider,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettingsProvider,
        private readonly PlanPriceAdjustmentService $planPriceAdjustmentService,
    ) {
    }

    #[Route('/settings/renewal-pricing', name: 'admin_renewal_pricing_settings', methods: ['GET', 'POST'])]
    public function renewalPricingSettings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $discountRaw = trim((string) $request->request->get('pricing_global_discount_percent', '0'));
            $discountPercent = is_numeric($discountRaw) ? (float) $discountRaw : -1;
            $trafficMinRaw = trim((string) $request->request->get('traffic_addon_min_gb', '1'));
            $trafficMaxRaw = trim((string) $request->request->get('traffic_addon_max_gb', '100'));
            $trafficPriceRaw = trim((string) $request->request->get('traffic_addon_price_per_gb', '0'));
            $trafficMinGb = is_numeric($trafficMinRaw) ? (int) floor((float) $trafficMinRaw) : 0;
            $trafficMaxGb = is_numeric($trafficMaxRaw) ? (int) floor((float) $trafficMaxRaw) : 0;
            $trafficPricePerGb = is_numeric($trafficPriceRaw) ? (int) floor((float) $trafficPriceRaw) : -1;

            if ($discountPercent < 0 || $discountPercent > 100) {
                $this->addFlash('danger', 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.');
            } elseif ($trafficMinGb <= 0 || $trafficMaxGb <= 0 || $trafficMaxGb < $trafficMinGb) {
                $this->addFlash('danger', 'حداقل و حداکثر حجم اضافه معتبر نیست.');
            } elseif ($trafficPricePerGb < 0) {
                $this->addFlash('danger', 'قیمت هر گیگ حجم اضافه معتبر نیست.');
            } else {
                $this->upsertSetting('renewal.carry_remaining_traffic', $request->request->has('renewal_carry_remaining_traffic') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('renewal.carry_remaining_days', $request->request->has('renewal_carry_remaining_days') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('renewal.expired_start_from_now', $request->request->has('renewal_expired_start_from_now') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('pricing.global_discount_percent', (string) floor($discountPercent), 'number');
                $this->upsertSetting('traffic_addon.enabled', $request->request->has('traffic_addon_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('traffic_addon.min_gb', (string) $trafficMinGb, 'number');
                $this->upsertSetting('traffic_addon.max_gb', (string) $trafficMaxGb, 'number');
                $this->upsertSetting('traffic_addon.price_per_gb', (string) $trafficPricePerGb, 'number');
                $this->entityManager->flush();
                $this->addFlash('success', 'تنظیمات تمدید و قیمتگذاری بروزرسانی شد.');
            }
        }

        return $this->render('admin/renewal_pricing_settings.html.twig', [
            'values' => [
                'renewal_carry_remaining_traffic' => $this->renewalSettingsProvider->carryRemainingTraffic(),
                'renewal_carry_remaining_days' => $this->renewalSettingsProvider->carryRemainingDays(),
                'renewal_expired_start_from_now' => $this->renewalSettingsProvider->expiredStartFromNow(),
                'pricing_global_discount_percent' => $this->renewalSettingsProvider->globalDiscountPercent(),
                'traffic_addon_enabled' => $this->trafficAddonSettingsProvider->enabled(),
                'traffic_addon_min_gb' => $this->trafficAddonSettingsProvider->minGb(),
                'traffic_addon_max_gb' => $this->trafficAddonSettingsProvider->maxGb(),
                'traffic_addon_price_per_gb' => $this->trafficAddonSettingsProvider->pricePerGb(),
            ],
        ]);
    }

    #[Route('/plans/bulk-adjust-prices', name: 'admin_bulk_plan_price_adjustment', methods: ['GET', 'POST'])]
    public function bulkPlanPriceAdjustment(Request $request): Response
    {
        $input = [
            'adjustmentType' => (string) $request->request->get('adjustmentType', 'percent'),
            'value' => trim((string) $request->request->get('value', '')),
            'direction' => (string) $request->request->get('direction', 'increase'),
            'field' => (string) $request->request->get('field', 'price'),
        ];
        $changes = [];

        if ($request->isMethod('POST')) {
            $direction = $input['direction'];
            $field = $input['field'];
            $value = $input['value'];
            $adjustmentType = $input['adjustmentType'];
            $percent = null;
            $amount = null;

            if (!in_array($direction, ['increase', 'decrease'], true) || !in_array($field, ['price', 'pricePerGb', 'pricePerDay', 'all'], true)) {
                $this->addFlash('danger', 'مقادیر فرم معتبر نیست.');
            } elseif (!is_numeric($value) || (float) $value < 0) {
                $this->addFlash('danger', 'مقدار باید عدد مثبت باشد.');
            } else {
                if ('amount' === $adjustmentType) {
                    $amount = (int) floor((float) $value);
                } else {
                    $percent = (float) $value;
                }

                $preview = $this->planPriceAdjustmentService->preview($direction, $field, $percent, $amount);
                $changes = $preview['changes'];

                if ('apply' === (string) $request->request->get('mode', 'preview')) {
                    if (!$request->request->has('confirm_apply')) {
                        $this->addFlash('warning', 'برای اعمال، تایید نهایی را فعال کنید.');
                    } else {
                        $applied = $this->planPriceAdjustmentService->apply($changes);
                        $this->addFlash('success', sprintf('تغییر گروهی قیمت انجام شد. %d تغییر اعمال شد.', $applied));
                        $changes = [];
                    }
                }
            }
        }

        return $this->render('admin/bulk_plan_price_adjustment.html.twig', [
            'input' => $input,
            'changes' => $changes,
        ]);
    }

    #[Route('/settings/automation', name: 'admin_automation_settings', methods: ['GET', 'POST'])]
    public function automationSettings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $batchLimitRaw = trim((string) $request->request->get('automation_batch_limit', '100'));
            $batchLimit = is_numeric($batchLimitRaw) ? (int) $batchLimitRaw : 0;
            if ($batchLimit <= 0) {
                $this->addFlash('danger', 'مقدار batch limit باید بزرگتر از صفر باشد.');
            } else {
                $this->upsertSetting('automation.sync_usage_enabled', $request->request->has('automation_sync_usage_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('automation.check_expiry_enabled', $request->request->has('automation_check_expiry_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('automation.send_notifications_enabled', $request->request->has('automation_send_notifications_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('automation.auto_suspend_expired_enabled', $request->request->has('automation_auto_suspend_expired_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('automation.auto_suspend_traffic_exhausted_enabled', $request->request->has('automation_auto_suspend_traffic_exhausted_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('automation.batch_limit', (string) $batchLimit, 'number');
                $this->entityManager->flush();
                $this->addFlash('success', 'تنظیمات اتوماسیون بروزرسانی شد.');
            }
        }

        return $this->render('admin/automation_settings.html.twig', [
            'values' => [
                'automation_sync_usage_enabled' => $this->automationSettingsProvider->syncUsageEnabled(),
                'automation_check_expiry_enabled' => $this->automationSettingsProvider->checkExpiryEnabled(),
                'automation_send_notifications_enabled' => $this->automationSettingsProvider->sendNotificationsEnabled(),
                'automation_auto_suspend_expired_enabled' => $this->automationSettingsProvider->autoSuspendExpiredEnabled(),
                'automation_auto_suspend_traffic_exhausted_enabled' => $this->automationSettingsProvider->autoSuspendTrafficExhaustedEnabled(),
                'automation_batch_limit' => $this->automationSettingsProvider->batchLimit(),
            ],
        ]);
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
