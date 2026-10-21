<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Shop\Application\PlanPriceAdjustmentService;
use App\Security\AdminAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk plan price adjustment (linked from the plans list). The renewal pricing and automation settings that used to
 * live here are tabs of SettingsAdminController now; their old URLs redirect there (LegacyAdminRoutes).
 */
#[Route('/admin')]
final class RenewalPricingAdminController extends AbstractController
{
    public function __construct(
        private readonly PlanPriceAdjustmentService $planPriceAdjustmentService,
    ) {
    }

    #[Route('/plans/bulk-adjust-prices', name: 'admin_bulk_plan_price_adjustment', methods: ['GET', 'POST'])]
    public function bulkPlanPriceAdjustment(Request $request, AdminAuditLogger $auditLogger): Response
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
                        $auditLogger->record('plan.bulk_price_adjust', 'Plan', null, ['direction' => $direction, 'field' => $field, 'percent' => $percent, 'amount' => $amount, 'applied' => $applied]);
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
}
