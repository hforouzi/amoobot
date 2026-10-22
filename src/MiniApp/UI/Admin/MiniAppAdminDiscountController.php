<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Entity\Plan;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Admin\Catalog\CatalogInput;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
use App\Shop\Application\Catalog\DiscountAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;
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
 * Discount codes in the Mini App admin (DISCOUNTS_MANAGE): status chips (active, inactive, expired, used up), search
 * by code, the web form's fields with live validation, a random code helper, activate / deactivate, the usage list
 * (read only), and delete, only for a code that was never used (a used code is deactivated instead, so its usage
 * history stays). Writes go through DiscountAdminService; the rules are the entity's.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminDiscountController extends AbstractController
{
    public const TABS = ['all', 'active', 'inactive', 'expired', 'exhausted'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiscountAdminService $discounts,
        private readonly CatalogInput $input,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/admin/discounts', name: 'miniapp_admin_discounts', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tab = in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : 'all';
        $query = AdminQuery::text($request);
        $page = AdminQuery::page($request);
        $qb = $this->tab($this->entityManager->createQueryBuilder()->select('d')->from(DiscountCode::class, 'd'), $tab)->orderBy('d.id', 'DESC');
        if (null !== $query) {
            $qb->andWhere('d.code LIKE :q')->setParameter('q', '%'.addcslashes(DiscountAdminService::normalize($query), '%_\\').'%');
        }
        $items = $qb->setFirstResult($page * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1)->getQuery()->getResult();
        $counts = [];
        foreach (self::TABS as $key) {
            $counts[$key] = (int) $this->tab($this->entityManager->createQueryBuilder()->select('COUNT(d.id)')->from(DiscountCode::class, 'd'), $key)->getQuery()->getSingleScalarResult();
        }

        return $this->fragments->render($request, 'miniapp/admin/catalog/discounts.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'query' => $query,
            'list' => ['items' => array_slice($items, 0, AdminLists::PAGE_SIZE), 'page' => $page, 'hasNext' => count($items) > AdminLists::PAGE_SIZE],
            'now' => $this->clock->now(),
        ]);
    }

    #[Route('/app/admin/discounts/new', name: 'miniapp_admin_discount_new', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]
    public function new(Request $request): Response
    {
        return $this->form($request, new DiscountCode());
    }

    #[Route('/app/admin/discounts/{id}', name: 'miniapp_admin_discount', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->code($id));
    }

    #[Route('/app/admin/discounts/{id}/usages', name: 'miniapp_admin_discount_usages', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]
    public function usages(Request $request, int $id): Response
    {
        $code = $this->code($id);
        $page = AdminQuery::page($request);
        $items = $this->entityManager->createQueryBuilder()->select('u', 'usr', 't', 'o')->from(DiscountUsage::class, 'u')
            ->join('u.user', 'usr')->leftJoin('usr.telegramAccount', 't')->leftJoin('u.order', 'o')
            ->where('u.discountCode = :code')->setParameter('code', $code)->orderBy('u.id', 'DESC')
            ->setFirstResult($page * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1)->getQuery()->getResult();

        return $this->fragments->render($request, 'miniapp/admin/catalog/discount_usages.html.twig', [
            'code' => $code,
            'list' => ['items' => array_slice($items, 0, AdminLists::PAGE_SIZE), 'page' => $page, 'hasNext' => count($items) > AdminLists::PAGE_SIZE],
        ]);
    }

    #[Route('/app/api/admin/discounts', name: 'miniapp_admin_discount_create', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $code = new DiscountCode();
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, 0, null,
            fn (): array => $this->input->toDiscount($code, $body),
            fn (): array => $this->discounts->violations($code),
            fn (): ?string => null,
            function () use ($request, $user, $code): array {
                $this->discounts->save($code);
                $this->auditLogger->record('discount_code.create', 'DiscountCode', $code->getId(), ['code' => $code->getCode(), 'type' => $code->getType(), 'value' => $code->getValue(), 'isActive' => $code->isActive()], $user->admin);

                return ['message' => $this->message($request, 'admin.discount.created'), 'redirect' => $this->generateUrl('miniapp_admin_discount', ['id' => $code->getId()])];
            },
        );
    }

    #[Route('/app/api/admin/discounts/{id}', name: 'miniapp_admin_discount_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $code = $this->code($id);
        $before = CatalogInput::snapshot($code, CatalogInput::DISCOUNT_FIELDS);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $code,
            fn (): array => $this->input->toDiscount($code, $body),
            fn (): array => $this->discounts->violations($code),
            fn (): ?string => null,
            function () use ($request, $user, $code, $before): array {
                $diff = CatalogEditor::diff($before, CatalogInput::snapshot($code, CatalogInput::DISCOUNT_FIELDS), ['value', 'isActive']);
                $this->discounts->save($code);
                $this->auditLogger->record('discount_code.update', 'DiscountCode', $code->getId(), $diff, $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : 'admin.discount.saved')];
            },
        );
    }

    /** Live validation: the same rules (duplicate code, percent range, dates in order), nothing saved. */
    #[Route('/app/api/admin/discounts/check', name: 'miniapp_admin_discount_check', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $body = AdminForms::body($request);
        $id = (int) ($body['id'] ?? 0);
        $code = $id > 0 ? $this->code($id) : new DiscountCode();

        return $this->editor->check(
            $request, $id > 0 ? $code : null,
            fn (): array => $this->input->toDiscount($code, $body),
            fn (): array => $this->discounts->violations($code),
            static fn (): array => [],
        );
    }

    /** A random unused code to start from (nothing is saved). */
    #[Route('/app/api/admin/discounts/generate-code', name: 'miniapp_admin_discount_generate', defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['GET'])]
    public function generate(): JsonResponse
    {
        return AdminActions::json(['code' => $this->discounts->generateCode()]);
    }

    #[Route('/app/api/admin/discounts/{id}/activate', name: 'miniapp_admin_discount_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function activate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, true);
    }

    #[Route('/app/api/admin/discounts/{id}/deactivate', name: 'miniapp_admin_discount_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function deactivate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $id, false);
    }

    #[Route('/app/api/admin/discounts/{id}/delete', name: 'miniapp_admin_discount_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.discounts'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $code = $this->code($id);
        if ($this->used($code)) {
            return AdminActions::json(['error' => 'discount_used', 'message' => $this->message($request, 'admin.discount.used_cannot_delete')], Response::HTTP_CONFLICT);
        }
        $confirm = $this->message($request, 'admin.discount.confirm.delete', ['%code%' => $code->getCode()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $code, $id): array {
            if ($this->used($code)) {
                return ['error' => 'discount_used', 'message' => $this->message($request, 'admin.discount.used_cannot_delete'), 'status' => Response::HTTP_CONFLICT];
            }
            $value = $code->getCode();
            $this->discounts->delete($code);
            $this->auditLogger->record('discount_code.delete', 'DiscountCode', $id, ['code' => $value], $user->admin);

            return ['message' => $this->message($request, 'admin.discount.deleted'), 'redirect' => $this->generateUrl('miniapp_admin_discounts')];
        });
    }

    private function toggle(Request $request, MiniAppUser $user, int $id, bool $active): JsonResponse
    {
        $code = $this->code($id);
        $confirm = $this->message($request, $active ? 'admin.discount.confirm.activate' : 'admin.discount.confirm.deactivate', ['%code%' => $code->getCode()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $code, $active): array {
            if (!$this->discounts->setActive($code, $active)) {
                return ['message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            // The bot's audit names (AdminDiscountsHandler).
            $this->auditLogger->record($active ? 'discount_code.activate' : 'discount_code.deactivate', 'DiscountCode', $code->getId(), ['code' => $code->getCode(), 'values' => ['isActive' => [!$active, $active]]], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.plan.activated' : 'admin.plan.deactivated')];
        });
    }

    private function used(DiscountCode $code): bool
    {
        return $code->getUsedCount() > 0 || $this->discounts->usageCount($code) > 0;
    }

    private function tab(QueryBuilder $qb, string $tab): QueryBuilder
    {
        $now = $this->clock->now();

        return match ($tab) {
            'active' => $qb->andWhere('d.isActive = true AND (d.endsAt IS NULL OR d.endsAt > :now) AND (d.maxUses IS NULL OR d.usedCount < d.maxUses)')->setParameter('now', $now),
            'inactive' => $qb->andWhere('d.isActive = false'),
            'expired' => $qb->andWhere('d.endsAt IS NOT NULL AND d.endsAt <= :now')->setParameter('now', $now),
            'exhausted' => $qb->andWhere('d.maxUses IS NOT NULL AND d.usedCount >= d.maxUses'),
            default => $qb,
        };
    }

    private function form(Request $request, DiscountCode $code): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/catalog/discount.html.twig', [
            'code' => $code,
            'values' => CatalogInput::snapshot($code, null === $code->getId() ? array_diff_key(CatalogInput::DISCOUNT_FIELDS, ['code' => 1]) : CatalogInput::DISCOUNT_FIELDS),
            'plans' => $this->entityManager->getRepository(Plan::class)->findBy([], ['id' => 'ASC']),
            'usageCount' => null === $code->getId() ? 0 : $this->discounts->usageCount($code),
            'used' => null !== $code->getId() && $this->used($code),
            'types' => DiscountCode::allowedTypes(),
            'appliesTo' => DiscountCode::allowedAppliesTo(),
        ]);
    }

    private function code(int $id): DiscountCode
    {
        return $this->entityManager->find(DiscountCode::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
