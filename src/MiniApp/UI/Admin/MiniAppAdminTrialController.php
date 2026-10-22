<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Admin\Catalog\CatalogInput;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\TrialPlanAdminService;
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
 * Trial plans in the Mini App admin (PLANS_MANAGE, as on the web): the list in the web's order (sort order, then
 * creation), the web form's fields, create, edit, activate / deactivate; and the claims, read only, searchable by
 * customer (Telegram id, @username or name). Writes go through TrialPlanAdminService; the rules are the entity's.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminTrialController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrialPlanAdminService $trialPlans,
        private readonly CatalogInput $input,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminRouteAccess $access,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/admin/trial-plans', name: 'miniapp_admin_trial_plans', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/catalog/trial_plans.html.twig', [
            'trialPlans' => $this->entityManager->getRepository(TrialPlan::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
            'can' => $this->access->map($user, ['miniapp_admin_trial_plan_activate', 'miniapp_admin_trial_plan_deactivate']),
        ]);
    }

    #[Route('/app/admin/trial-plans/new', name: 'miniapp_admin_trial_plan_new', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new TrialPlan());
    }

    #[Route('/app/admin/trial-plans/{id}', name: 'miniapp_admin_trial_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->trialPlan($id));
    }

    #[Route('/app/api/admin/trial-plans', name: 'miniapp_admin_trial_plan_create', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $trial = new TrialPlan();
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, 0, null,
            fn (): array => $this->input->toTrialPlan($trial, $body),
            fn (): array => $this->trialPlans->violations($trial),
            fn (): ?string => null,
            function () use ($request, $user, $trial): array {
                $this->trialPlans->save($trial);
                $this->auditLogger->record('trial_plan.create', 'TrialPlan', $trial->getId(), ['title' => $trial->getTitle(), 'isActive' => $trial->isActive()], $user->admin);

                return ['message' => $this->message($request, 'admin.trial.created'), 'redirect' => $this->generateUrl('miniapp_admin_trial_plan', ['id' => $trial->getId()])];
            },
        );
    }

    #[Route('/app/api/admin/trial-plans/{id}', name: 'miniapp_admin_trial_plan_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $trial = $this->trialPlan($id);
        $before = CatalogInput::snapshot($trial, CatalogInput::TRIAL_FIELDS);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $trial,
            fn (): array => $this->input->toTrialPlan($trial, $body),
            fn (): array => $this->trialPlans->violations($trial),
            fn (): ?string => null,
            function () use ($request, $user, $trial, $before): array {
                $diff = CatalogEditor::diff($before, CatalogInput::snapshot($trial, CatalogInput::TRIAL_FIELDS), ['isActive']);
                $this->trialPlans->save($trial);
                $this->auditLogger->record('trial_plan.update', 'TrialPlan', $trial->getId(), $diff, $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : 'admin.trial.saved')];
            },
        );
    }

    #[Route('/app/api/admin/trial-plans/check', name: 'miniapp_admin_trial_plan_check', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $body = AdminForms::body($request);
        $id = (int) ($body['id'] ?? 0);
        $trial = $id > 0 ? $this->trialPlan($id) : new TrialPlan();

        return $this->editor->check(
            $request, $id > 0 ? $trial : null,
            fn (): array => $this->input->toTrialPlan($trial, $body),
            fn (): array => $this->trialPlans->violations($trial),
            static fn (): array => [],
        );
    }

    #[Route('/app/api/admin/trial-plans/{id}/activate', name: 'miniapp_admin_trial_plan_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, true);
    }

    #[Route('/app/api/admin/trial-plans/{id}/deactivate', name: 'miniapp_admin_trial_plan_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.plans'], methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, false);
    }

    /** Read only, newest first; searchable by the customer's Telegram id, @username or name. */
    #[Route('/app/admin/trial-claims', name: 'miniapp_admin_trial_claims', defaults: ['_miniapp_section' => 'admin.plans'], methods: ['GET'])]
    public function claims(Request $request): Response
    {
        $query = AdminQuery::text($request);
        $page = AdminQuery::page($request);
        $qb = $this->entityManager->createQueryBuilder()->select('c', 't', 'p')->from(TrialClaim::class, 'c')
            ->join('c.telegramAccount', 't')->join('c.trialPlan', 'p')->orderBy('c.id', 'DESC');
        if (null !== $query) {
            $like = '%'.addcslashes(ltrim($query, '@'), '%_\\').'%';
            $qb->andWhere('t.telegramId = :exact OR t.username LIKE :q OR t.firstName LIKE :q OR t.lastName LIKE :q')->setParameter('exact', $query)->setParameter('q', $like);
        }
        $items = $qb->setFirstResult($page * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1)->getQuery()->getResult();

        return $this->fragments->render($request, 'miniapp/admin/catalog/trial_claims.html.twig', [
            'query' => $query,
            'list' => ['items' => array_slice($items, 0, AdminLists::PAGE_SIZE), 'page' => $page, 'hasNext' => count($items) > AdminLists::PAGE_SIZE],
        ]);
    }

    private function toggle(Request $request, MiniAppUser $user, int $id, bool $active): JsonResponse
    {
        $trial = $this->trialPlan($id);
        $confirm = $this->message($request, $active ? 'admin.trial.confirm.activate' : 'admin.trial.confirm.deactivate', ['%title%' => $trial->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $trial, $active): array {
            if (!$this->trialPlans->setActive($trial, $active)) {
                return ['message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            $this->auditLogger->record($active ? 'trial_plan.activate' : 'trial_plan.deactivate', 'TrialPlan', $trial->getId(), ['title' => $trial->getTitle(), 'values' => ['isActive' => [!$active, $active]]], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.plan.activated' : 'admin.plan.deactivated')];
        });
    }

    private function form(Request $request, TrialPlan $trial): Response
    {
        /** @var list<VpnInbound> $inbounds */
        $inbounds = $this->entityManager->createQueryBuilder()->select('i', 'p')->from(VpnInbound::class, 'i')->join('i.panel', 'p')
            ->orderBy('p.title', 'ASC')->addOrderBy('i.id', 'ASC')->getQuery()->getResult();

        return $this->fragments->render($request, 'miniapp/admin/catalog/trial_plan.html.twig', [
            'trial' => $trial,
            'values' => CatalogInput::snapshot($trial, null === $trial->getId() ? array_diff_key(CatalogInput::TRIAL_FIELDS, ['title' => 1]) : CatalogInput::TRIAL_FIELDS),
            'inbounds' => AdminForms::inboundChoices($inbounds),
        ]);
    }

    private function trialPlan(int $id): TrialPlan
    {
        return $this->entityManager->find(TrialPlan::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
