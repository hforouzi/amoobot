<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\Plan;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
use App\Shop\Application\PlanPriceAdjustmentService;
use App\Shop\Application\PlanScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bulk percent price change of plans (PLANS_MANAGE): the active plans or a selection, a signed percent (as the bot:
 * -90 to +200, Persian digits accepted), the price fields to change; a preview (how many plans and values change,
 * a before / after sample), then apply after confirming. The numbers are PlanPriceAdjustmentService's, the same as
 * the web panel's and the bot's (floor of the percentage, never below 0).
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminPlanBulkController extends AbstractController
{
    public const RANGE = [-90.0, 200.0];
    public const FIELDS = ['price', 'pricePerGb', 'pricePerDay', 'all'];
    private const SAMPLE = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanPriceAdjustmentService $adjustment,
        private readonly AdminActions $actions,
        private readonly CatalogEditor $editor,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/admin/plans/bulk', name: 'miniapp_admin_plan_bulk', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function page(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/catalog/bulk.html.twig', [
            'plans' => $this->entityManager->getRepository(Plan::class)->findBy([], ['id' => 'ASC']),
            'fields' => self::FIELDS,
            'range' => self::RANGE,
        ]);
    }

    #[Route('/app/api/admin/plans/bulk/preview', name: 'miniapp_admin_plan_bulk_preview', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $input = $this->input($request);
        if (is_array($input['errors'])) {
            return $this->editor->invalid($request, $input['errors']);
        }
        $changes = $this->changes($input);
        $html = (string) $this->fragments->render($request, 'miniapp/admin/catalog/_bulk_preview.html.twig', $this->summary($changes) + ['percent' => $input['percent']])->getContent();

        return AdminActions::json(['html' => $html] + $this->summary($changes, false));
    }

    #[Route('/app/api/admin/plans/bulk/apply', name: 'miniapp_admin_plan_bulk_apply', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function apply(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $input = $this->input($request);
        if (is_array($input['errors'])) {
            return $this->editor->invalid($request, $input['errors']);
        }
        $summary = $this->summary($this->changes($input), false);
        if (0 === $summary['changes']) {
            return AdminActions::json(['error' => 'nothing_to_change', 'message' => $this->message($request, 'admin.bulk.none')], Response::HTTP_CONFLICT);
        }
        $confirm = $this->message($request, 'admin.bulk.confirm', ['%percent%' => self::signed($input['percent']), '%plans%' => $summary['plans'], '%changes%' => $summary['changes']]);

        return $this->actions->run($request, $user, 0, $confirm, function () use ($request, $user, $input): array {
            // Computed again under the confirmed call: what is applied is what the plans are now.
            $changes = $this->changes($input);
            $applied = $this->adjustment->apply($changes);
            $this->auditLogger->record('plan.bulk_price_adjust', 'Plan', null, [
                'direction' => $input['percent'] > 0 ? 'increase' : 'decrease',
                'field' => $input['field'],
                'percent' => $input['percent'],
                'scope' => null === $input['selection'] ? 'active' : 'selection',
                'plans' => $this->summary($changes, false)['plans'],
                'applied' => $applied,
            ], $user->admin);

            return ['message' => $this->message($request, 'admin.bulk.applied', ['%count%' => $applied]), 'redirect' => $this->generateUrl('miniapp_admin_plans')];
        });
    }

    /**
     * @return array{percent: float, field: string, selection: list<int>|null, errors: array<string, list<string>>|null}
     */
    private function input(Request $request): array
    {
        $body = AdminForms::body($request);
        $errors = [];
        $raw = str_replace(['٫', '/', ','], '.', AdminText::latinDigits(trim((string) ($body['percent'] ?? ''))));
        $raw = str_replace(['−', '–'], '-', $raw);
        $percent = 1 === preg_match('/^[+-]?\d{1,3}(\.\d{1,2})?$/', $raw) ? (float) $raw : null;
        if (null === $percent || 0.0 === $percent || $percent < self::RANGE[0] || $percent > self::RANGE[1]) {
            $errors['percent'] = [$this->message($request, 'admin.bulk.invalid_percent', ['%min%' => self::RANGE[0], '%max%' => '+'.self::RANGE[1]])];
        }
        $field = in_array($body['field'] ?? null, self::FIELDS, true) ? (string) $body['field'] : 'price';
        $selection = null;
        if ('selection' === ($body['scope'] ?? 'active')) {
            $ids = is_array($body['plans'] ?? null) ? $body['plans'] : [];
            $selection = array_values(array_unique(array_map('intval', array_filter($ids, static fn (mixed $id): bool => is_scalar($id) && ctype_digit((string) $id)))));
            if ([] === $selection) {
                $errors['plans'] = [$this->message($request, 'admin.bulk.choose_plans')];
            }
        }

        return ['percent' => (float) $percent, 'field' => $field, 'selection' => $selection, 'errors' => [] === $errors ? null : $errors];
    }

    /**
     * @param array{percent: float, field: string, selection: list<int>|null, errors: mixed} $input
     *
     * @return list<array{plan: Plan, field: string, before: int, after: int}>
     */
    private function changes(array $input): array
    {
        $scope = null === $input['selection'] ? new PlanScope(activeOnly: true) : new PlanScope(planIds: $input['selection']);

        return $this->adjustment->preview($input['percent'] > 0 ? 'increase' : 'decrease', $input['field'], abs($input['percent']), null, $scope)['changes'];
    }

    /**
     * @param list<array{plan: Plan, field: string, before: int, after: int}> $changes
     *
     * @return array{plans: int, changes: int, sample?: list<array{title: string, field: string, before: int, after: int}>}
     */
    private function summary(array $changes, bool $withSample = true): array
    {
        $summary = [
            'plans' => count(array_unique(array_map(static fn (array $c): int => (int) $c['plan']->getId(), $changes))),
            'changes' => count($changes),
        ];
        if ($withSample) {
            $summary['sample'] = array_map(static fn (array $c): array => ['title' => $c['plan']->getTitle(), 'field' => $c['field'], 'before' => $c['before'], 'after' => $c['after']], array_slice($changes, 0, self::SAMPLE));
        }

        return $summary;
    }

    private static function signed(float $percent): string
    {
        return ($percent > 0 ? '+' : '').rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    /**
     * @param array<string, int|float|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
