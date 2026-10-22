<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Admin\Catalog\CatalogInput;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\PlanInUse;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Plans in the Mini App admin (PLANS_MANAGE): the list (active / inactive, search, the price plausibility badge), the
 * web form's fields in sections, create, edit (a price change is confirmed old -> new, with the plausibility
 * warnings), duplicate, activate / deactivate and delete (refused while orders refer to the plan). Every write goes
 * through PlanAdminService, as on the web and in the bot; the rules are the entity's.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminPlanController extends AbstractController
{
    public const TABS = ['all', 'active', 'inactive'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanAdminService $plans,
        private readonly PlanPriceSanityChecker $sanity,
        private readonly CatalogInput $input,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminRouteAccess $access,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/admin/plans', name: 'miniapp_admin_plans', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $tab = in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : 'all';
        $query = AdminQuery::text($request);
        $qb = $this->entityManager->createQueryBuilder()->select('p', 'i', 'panel')->from(Plan::class, 'p')
            ->leftJoin('p.inbound', 'i')->leftJoin('i.panel', 'panel')
            // As the web list and the bot: in creation order.
            ->orderBy('p.id', 'ASC');
        if ('all' !== $tab) {
            $qb->andWhere('p.isActive = :active')->setParameter('active', 'active' === $tab);
        }
        if (null !== $query) {
            $qb->andWhere('p.title LIKE :q')->setParameter('q', '%'.addcslashes($query, '%_\\').'%');
        }
        /** @var list<Plan> $plans */
        $plans = $qb->getQuery()->getResult();
        $counts = ['all' => 0, 'active' => 0, 'inactive' => 0];
        foreach ($this->entityManager->createQueryBuilder()->select('p.isActive AS active', 'COUNT(p.id) AS n')->from(Plan::class, 'p')->groupBy('p.isActive')->getQuery()->getArrayResult() as $row) {
            $counts[$row['active'] ? 'active' : 'inactive'] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }

        return $this->fragments->render($request, 'miniapp/admin/catalog/plans.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'query' => $query,
            'plans' => $plans,
            'warned' => array_map(fn (Plan $plan): bool => [] !== $this->sanity->issues($plan), $plans),
            'can' => $this->access->map($user, ['miniapp_admin_plan_activate', 'miniapp_admin_plan_deactivate', 'miniapp_admin_trial_plans', 'miniapp_admin_plan_bulk']),
        ]);
    }

    #[Route('/app/admin/plans/new', name: 'miniapp_admin_plan_new', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new Plan());
    }

    #[Route('/app/admin/plans/{id}', name: 'miniapp_admin_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->plan($id));
    }

    #[Route('/app/api/admin/plans', name: 'miniapp_admin_plan_create', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $plan = new Plan();
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, 0, null,
            fn (): array => $this->input->toPlan($plan, $body),
            fn (): array => $this->plans->violations($plan),
            fn (): ?string => null,
            function () use ($request, $user, $plan): array {
                $this->plans->save($plan);
                $this->auditLogger->record('plan.create', 'Plan', $plan->getId(), ['title' => $plan->getTitle(), 'price' => $plan->getPrice(), 'isActive' => $plan->isActive()], $user->admin);

                return ['message' => $this->message($request, 'admin.plan.created'), 'redirect' => $this->generateUrl('miniapp_admin_plan', ['id' => $plan->getId()])];
            },
        );
    }

    /** A change to a price is confirmed old -> new (with the plausibility warnings); other edits save at once. */
    #[Route('/app/api/admin/plans/{id}', name: 'miniapp_admin_plan_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $plan = $this->plan($id);
        $before = CatalogInput::snapshot($plan, CatalogInput::PLAN_FIELDS);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $plan,
            fn (): array => $this->input->toPlan($plan, $body),
            fn (): array => $this->plans->violations($plan),
            fn (): ?string => $this->priceConfirmation($request, $plan, $before),
            function () use ($request, $user, $plan, $before): array {
                $diff = CatalogEditor::diff($before, CatalogInput::snapshot($plan, CatalogInput::PLAN_FIELDS), [...CatalogInput::PRICE_FIELDS, 'isActive']);
                $this->plans->save($plan);
                $this->auditLogger->record('plan.update', 'Plan', $plan->getId(), $diff, $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : 'admin.plan.saved'), 'reload' => true];
            },
        );
    }

    /** Live validation of the form: the same rules, nothing saved. */
    #[Route('/app/api/admin/plans/check', name: 'miniapp_admin_plan_check', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $body = AdminForms::body($request);
        $id = (int) ($body['id'] ?? 0);
        $plan = $id > 0 ? $this->plan($id) : new Plan();

        return $this->editor->check(
            $request, $id > 0 ? $plan : null,
            fn (): array => $this->input->toPlan($plan, $body),
            fn (): array => $this->plans->violations($plan),
            fn (): array => $this->warnings($request, $plan),
        );
    }

    /** A copy to edit: same settings, inactive, the name marked as a copy. */
    #[Route('/app/api/admin/plans/{id}/duplicate', name: 'miniapp_admin_plan_duplicate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function duplicate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $source = $this->plan($id);

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $source): array {
            $copy = new Plan();
            $this->input->toPlan($copy, array_map(static fn (mixed $v): mixed => true === $v ? '1' : (false === $v ? '' : $v), CatalogInput::snapshot($source, CatalogInput::PLAN_FIELDS)));
            $copy->setTitle(mb_substr($this->translator->trans('admin.plan.copy_of', ['%title%' => $source->getTitle()], 'miniapp', MiniAppLocale::fromRequest($request)), 0, 255))->setIsActive(false);
            $this->plans->save($copy);
            $this->auditLogger->record('plan.duplicate', 'Plan', $copy->getId(), ['from' => $source->getId()], $user->admin);

            return ['message' => $this->message($request, 'admin.plan.duplicated'), 'redirect' => $this->generateUrl('miniapp_admin_plan', ['id' => $copy->getId()])];
        });
    }

    #[Route('/app/api/admin/plans/{id}/activate', name: 'miniapp_admin_plan_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, true);
    }

    #[Route('/app/api/admin/plans/{id}/deactivate', name: 'miniapp_admin_plan_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, false);
    }

    /** Refused while orders refer to the plan (as on the web): deactivate it instead. */
    #[Route('/app/api/admin/plans/{id}/delete', name: 'miniapp_admin_plan_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $plan = $this->plan($id);
        $confirm = $this->translator->trans('admin.plan.confirm.delete', ['%title%' => $plan->getTitle()], 'miniapp', MiniAppLocale::fromRequest($request));

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $plan, $id): array {
            $title = $plan->getTitle();
            try {
                $this->plans->delete($plan);
            } catch (PlanInUse $e) {
                return ['error' => 'plan_in_use', 'message' => $this->message($request, 'admin.plan.in_use', ['%count%' => $e->orderCount]), 'status' => Response::HTTP_CONFLICT];
            }
            $this->auditLogger->record('plan.delete', 'Plan', $id, ['title' => $title], $user->admin);

            return ['message' => $this->message($request, 'admin.plan.deleted'), 'redirect' => $this->generateUrl('miniapp_admin_plans')];
        });
    }

    private function toggle(Request $request, MiniAppUser $user, int $id, bool $active): JsonResponse
    {
        $plan = $this->plan($id);
        $confirm = $this->translator->trans($active ? 'admin.plan.confirm.activate' : 'admin.plan.confirm.deactivate', ['%title%' => $plan->getTitle()], 'miniapp', MiniAppLocale::fromRequest($request));

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $plan, $active): array {
            if (!$this->plans->setActive($plan, $active)) {
                return ['message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            // The bot's audit names (AdminPlansHandler).
            $this->auditLogger->record($active ? 'plan.activate' : 'plan.deactivate', 'Plan', $plan->getId(), ['title' => $plan->getTitle(), 'values' => ['isActive' => [!$active, $active]]], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.plan.activated' : 'admin.plan.deactivated')];
        });
    }

    /**
     * @param array<string, scalar|null> $before
     */
    private function priceConfirmation(Request $request, Plan $plan, array $before): ?string
    {
        $locale = MiniAppLocale::fromRequest($request);
        $lines = [];
        foreach (CatalogInput::PRICE_FIELDS as $field) {
            $after = CatalogInput::snapshot($plan, [$field => 'int'])[$field];
            if ($after !== $before[$field]) {
                $lines[] = $this->translator->trans('admin.plan.price_change', [
                    '%field%' => $this->translator->trans('field.'.AdminFormFilters::snake($field), [], 'admin', $locale),
                    '%old%' => null === $before[$field] ? '—' : number_format((int) $before[$field]),
                    '%new%' => null === $after ? '—' : number_format((int) $after),
                ], 'miniapp', $locale);
            }
        }
        if ([] === $lines) {
            return null;
        }

        return implode("\n", [...$lines, ...$this->warnings($request, $plan)]);
    }

    /**
     * The price plausibility findings (PlanPriceSanityChecker) in the admin's language.
     *
     * @return list<string>
     */
    private function warnings(Request $request, Plan $plan): array
    {
        $locale = MiniAppLocale::fromRequest($request);

        return array_map(fn (array $issue): string => $this->translator->trans('admin.plan.warning.'.$issue['kind'], [
            '%field%' => $this->translator->trans('field.'.AdminFormFilters::snake($issue['field']), [], 'admin', $locale),
            '%value%' => number_format($issue['value']),
            '%min%' => number_format($issue['min']),
            '%max%' => number_format($issue['max']),
            '%tenth%' => number_format(intdiv($issue['value'], 10)),
        ], 'miniapp', $locale), $this->sanity->issues($plan));
    }

    private function form(Request $request, Plan $plan): Response
    {
        /** @var list<VpnInbound> $inbounds */
        $inbounds = $this->entityManager->createQueryBuilder()->select('i', 'p')->from(VpnInbound::class, 'i')->join('i.panel', 'p')
            ->orderBy('p.title', 'ASC')->addOrderBy('i.id', 'ASC')->getQuery()->getResult();

        return $this->fragments->render($request, 'miniapp/admin/catalog/plan.html.twig', [
            'plan' => $plan,
            'values' => CatalogInput::snapshot($plan, null === $plan->getId() ? array_diff_key(CatalogInput::PLAN_FIELDS, ['title' => 1, 'durationDays' => 1, 'price' => 1]) : CatalogInput::PLAN_FIELDS),
            'inbounds' => AdminForms::inboundChoices($inbounds),
            'warnings' => null === $plan->getId() ? [] : $this->warnings($request, $plan),
            'orderCount' => $this->plans->orderCount($plan),
        ]);
    }

    private function plan(int $id): Plan
    {
        return $this->entityManager->find(Plan::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
