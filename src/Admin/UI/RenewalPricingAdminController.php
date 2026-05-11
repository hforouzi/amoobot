<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\Setting;
use App\Provisioning\Application\RenewalSettingsProvider;
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
        private readonly PlanPriceAdjustmentService $planPriceAdjustmentService,
    ) {
    }

    #[Route('/settings/renewal-pricing', name: 'admin_renewal_pricing_settings', methods: ['GET', 'POST'])]
    public function renewalPricingSettings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $discountRaw = trim((string) $request->request->get('pricing_global_discount_percent', '0'));
            $discountPercent = is_numeric($discountRaw) ? (float) $discountRaw : -1;
            if ($discountPercent < 0 || $discountPercent > 100) {
                $this->addFlash('danger', 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.');
            } else {
                $this->upsertSetting('renewal.carry_remaining_traffic', $request->request->has('renewal_carry_remaining_traffic') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('renewal.carry_remaining_days', $request->request->has('renewal_carry_remaining_days') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('renewal.expired_start_from_now', $request->request->has('renewal_expired_start_from_now') ? 'true' : 'false', 'boolean');
                $this->upsertSetting('pricing.global_discount_percent', (string) floor($discountPercent), 'number');
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
