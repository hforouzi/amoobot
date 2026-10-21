<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\DiscountCode;
use App\Entity\DiscountUsage;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: discount codes ("admin.d", DISCOUNTS_MANAGE): list with usage counts, active on/off. Creating / editing
 * a code stays in the web panel. Callbacks: l:<page>, t:<id> / tk:<id>:<0|1>.
 */
final class AdminDiscountsHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'd';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 't', 'tk'], true) ? [AdminPermission::DISCOUNTS_MANAGE] : [];
    }

    public function handle(AdminRequest $request): void
    {
        match ($request->verb) {
            't' => $this->askToggle($request, $request->intArg(0)),
            'tk' => $this->toggle($request, $request->intArg(0), '1' === $request->arg(1)),
            default => $this->list($request, $request->intArg(0)),
        };
    }

    private function list(AdminRequest $request, int $page): void
    {
        $repository = $this->entityManager->getRepository(DiscountCode::class);
        [$page, $pages] = $this->screen->page($page, $repository->count([]));
        /** @var list<DiscountCode> $codes */
        $codes = $repository->findBy([], ['id' => 'DESC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);
        $usage = $this->usageCounts($codes);

        $lines = [$this->screen->text('admin.discounts.title', ['page_info' => $this->screen->pageInfo($page, $pages)])];
        $rows = [];
        foreach ($codes as $code) {
            $lines[] = $this->screen->text('admin.discounts.line', [
                'state' => $code->isActive() ? '✅' : '⏸',
                'code' => $code->getCode(),
                'value' => DiscountCode::TYPE_PERCENT === $code->getType() ? $code->getValue().'%' : AdminScreen::money($code->getValue()).' تومان',
                'used' => $usage[(int) $code->getId()] ?? 0,
                'max' => $code->getMaxUses() ?? '∞',
            ]);
            $rows[] = [$this->screen->rawButton(($code->isActive() ? '⏸ ' : '▶️ ').$code->getCode(), 'admin.d:t:'.$code->getId())];
        }
        if ([] === $codes) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.web_only_hint');
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows, $this->screen->pagerRow($page, $pages, 'admin.d:l:%d')));
    }

    private function askToggle(AdminRequest $request, int $id): void
    {
        $code = $this->entityManager->getRepository(DiscountCode::class)->find($id);
        if (!$code instanceof DiscountCode) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.d:l:0');

            return;
        }
        $activate = !$code->isActive();
        $this->screen->confirm($request, $this->screen->text($activate ? 'admin.discounts.confirm_activate' : 'admin.discounts.confirm_deactivate', ['code' => $code->getCode()]), 'admin.d:tk:'.$id.':'.($activate ? '1' : '0'), 'admin.d:l:0');
    }

    private function toggle(AdminRequest $request, int $id, bool $active): void
    {
        $code = $this->entityManager->getRepository(DiscountCode::class)->find($id);
        if (!$code instanceof DiscountCode) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.d:l:0');

            return;
        }
        if ($code->isActive() === $active) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.d:l:0');

            return;
        }
        $code->setIsActive($active);
        $this->entityManager->flush();
        $this->screen->audit($request, $active ? 'discount_code.activate' : 'discount_code.deactivate', 'DiscountCode', $id, ['code' => $code->getCode()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.d:l:0');
    }

    /**
     * @param list<DiscountCode> $codes
     *
     * @return array<int, int> code id => times used
     */
    private function usageCounts(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(u.discountCode) AS code', 'COUNT(u.id) AS used')
            ->from(DiscountUsage::class, 'u')
            ->where('u.discountCode IN (:codes)')
            ->setParameter('codes', $codes)
            ->groupBy('u.discountCode')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['code']] = (int) $row['used'];
        }

        return $counts;
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
