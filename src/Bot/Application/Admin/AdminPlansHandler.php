<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Entity\Plan;
use App\Entity\TrialClaim;
use App\Security\AdminPermission;
use App\Shop\Application\PlanPriceAdjustmentService;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: plans ("admin.pl", PLANS_MANAGE) — list, detail, active on/off, price change, bulk percent change — and
 * the read-only trial claims list ("admin.tc"). Creating / editing a plan in full stays in the web panel.
 * Callbacks: l:<page>, v:<id>, t:<id> / tk:<id>:<0|1>, pr:<id> (prompt) / pk:<id>:<price>, bulk (prompt) / bk:<±pct>.
 */
final class AdminPlansHandler implements AdminArea
{
    private const PRICE_PROMPT = 'plan_price';
    private const BULK_PROMPT = 'plan_bulk';
    private const BULK_RANGE = [-90.0, 200.0];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanPriceAdjustmentService $priceAdjustment,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'pl';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 'v', 't', 'tk', 'pr', 'pk', 'bulk', 'bk', 'tc'], true) ? [AdminPermission::PLANS_MANAGE] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            'v' => $this->detail($request, $id),
            't' => $this->askToggle($request, $id),
            'tk' => $this->toggle($request, $id, '1' === $request->arg(1)),
            'pr' => $this->askPrice($request, $id),
            'pk' => $this->setPrice($request, $id, $request->intArg(1, -1)),
            'bulk' => $this->screen->prompt($request, self::BULK_PROMPT, '', $this->screen->text('admin.plans.bulk_prompt', ['min' => self::BULK_RANGE[0], 'max' => self::BULK_RANGE[1]])),
            'bk' => $this->bulk($request, $request->arg(0)),
            'tc' => $this->trialClaims($request, $id),
            default => $this->list($request, $id),
        };
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return in_array($kind, [self::PRICE_PROMPT, self::BULK_PROMPT], true) ? AdminPermission::PLANS_MANAGE : null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
        if (self::PRICE_PROMPT === $kind) {
            $plan = $this->entityManager->getRepository(Plan::class)->find((int) $target);
            [$min, $max] = PlanPriceSanityChecker::PRICE_RANGE;
            $price = AdminScreen::parseInt($text, $min, $max);
            if (!$plan instanceof Plan) {
                $this->screen->endPrompt($request);
                $this->screen->notice($request, 'admin.not_found', [], 'admin.pl:l:0');

                return;
            }
            if (null === $price) {
                $this->screen->send($request, $this->screen->text('admin.invalid_number', ['min' => AdminScreen::money($min), 'max' => AdminScreen::money($max)]));

                return;
            }
            $this->screen->endPrompt($request);
            $this->screen->confirm($request, $this->screen->text('admin.plans.confirm_price', ['title' => $plan->getTitle(), 'old' => AdminScreen::money($plan->getPrice()), 'new' => AdminScreen::money($price)]), 'admin.pl:pk:'.$plan->getId().':'.$price, 'admin.pl:v:'.$plan->getId());

            return;
        }

        $percent = AdminScreen::parseDecimal($text, self::BULK_RANGE[0], self::BULK_RANGE[1]);
        if (null === $percent || 0.0 === $percent) {
            $this->screen->send($request, $this->screen->text('admin.invalid_number', ['min' => self::BULK_RANGE[0], 'max' => self::BULK_RANGE[1]]));

            return;
        }
        $this->screen->endPrompt($request);
        $changes = $this->bulkChanges($percent);
        if ([] === $changes) {
            $this->screen->notice($request, 'admin.plans.bulk_none', [], 'admin.pl:l:0');

            return;
        }
        $signed = ($percent > 0 ? '+' : '').rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $this->screen->confirm($request, $this->screen->text('admin.plans.confirm_bulk', [
            'percent' => $signed,
            'plans' => count(array_unique(array_map(static fn (array $change): int => (int) $change['plan']->getId(), $changes))),
            'changes' => count($changes),
        ]), 'admin.pl:bk:'.$signed, 'admin.pl:l:0');
    }

    private function list(AdminRequest $request, int $page): void
    {
        $repository = $this->entityManager->getRepository(Plan::class);
        [$page, $pages] = $this->screen->page($page, $repository->count([]));
        /** @var list<Plan> $plans */
        $plans = $repository->findBy([], ['id' => 'ASC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);

        $lines = [$this->screen->text('admin.plans.title', ['page_info' => $this->screen->pageInfo($page, $pages)])];
        $buttons = [];
        foreach ($plans as $plan) {
            $lines[] = $this->screen->text('admin.plans.line', $this->variables($plan));
            $buttons[] = $this->screen->rawButton(($plan->isActive() ? '✅ ' : '⏸ ').'#'.$plan->getId().' '.$plan->getTitle(), 'admin.pl:v:'.$plan->getId());
        }
        if ([] === $plans) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.web_only_hint');

        $rows = array_chunk($buttons, 2);
        $rows[] = [$this->screen->button('button.admin.bulk_price', 'admin.pl:bulk')];
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard($rows, $this->screen->pagerRow($page, $pages, 'admin.pl:l:%d')));
    }

    private function detail(AdminRequest $request, int $id): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($id);
        if (!$plan instanceof Plan) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pl:l:0');

            return;
        }

        $this->screen->send($request, $this->screen->text('admin.plans.detail', $this->variables($plan) + [
            'days' => $plan->isUnlimitedDuration() ? '∞' : (string) $plan->getDurationDays(),
            'traffic' => null === $plan->getTrafficGb() ? '∞' : (string) $plan->getTrafficGb(),
        ]), $this->screen->listKeyboard([
            [$this->screen->button($plan->isActive() ? 'button.admin.deactivate' : 'button.admin.activate', 'admin.pl:t:'.$plan->getId())],
            [$this->screen->button('button.admin.change_price', 'admin.pl:pr:'.$plan->getId())],
        ], [], 'admin.pl:l:0'));
    }

    private function askToggle(AdminRequest $request, int $id): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($id);
        if (!$plan instanceof Plan) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pl:l:0');

            return;
        }
        $activate = !$plan->isActive();
        $this->screen->confirm($request, $this->screen->text($activate ? 'admin.plans.confirm_activate' : 'admin.plans.confirm_deactivate', ['title' => $plan->getTitle()]), 'admin.pl:tk:'.$plan->getId().':'.($activate ? '1' : '0'), 'admin.pl:v:'.$plan->getId());
    }

    private function toggle(AdminRequest $request, int $id, bool $active): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($id);
        if (!$plan instanceof Plan) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pl:l:0');

            return;
        }
        if ($plan->isActive() === $active) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.pl:v:'.$id);

            return;
        }
        $plan->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->screen->audit($request, $active ? 'plan.activate' : 'plan.deactivate', 'Plan', $id, ['title' => $plan->getTitle()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.pl:v:'.$id);
    }

    private function askPrice(AdminRequest $request, int $id): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($id);
        if (!$plan instanceof Plan) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.pl:l:0');

            return;
        }
        [$min, $max] = PlanPriceSanityChecker::PRICE_RANGE;
        $this->screen->prompt($request, self::PRICE_PROMPT, (string) $id, $this->screen->text('admin.plans.price_prompt', [
            'title' => $plan->getTitle(), 'price' => AdminScreen::money($plan->getPrice()), 'min' => AdminScreen::money($min), 'max' => AdminScreen::money($max),
        ]));
    }

    private function setPrice(AdminRequest $request, int $id, int $price): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($id);
        [$min, $max] = PlanPriceSanityChecker::PRICE_RANGE;
        if (!$plan instanceof Plan || $price < $min || $price > $max) {
            $this->screen->notice($request, $plan instanceof Plan ? 'admin.state_changed' : 'admin.not_found', [], 'admin.pl:l:0');

            return;
        }
        $before = $plan->getPrice();
        $plan->setPrice($price)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->screen->audit($request, 'plan.price_update', 'Plan', $id, ['before' => $before, 'after' => $price]);
        $this->screen->notice($request, 'admin.plans.price_done', ['title' => $plan->getTitle(), 'old' => AdminScreen::money($before), 'new' => AdminScreen::money($price)], 'admin.pl:v:'.$id);
    }

    private function bulk(AdminRequest $request, string $signedPercent): void
    {
        $percent = AdminScreen::parseDecimal($signedPercent, self::BULK_RANGE[0], self::BULK_RANGE[1]);
        if (null === $percent || 0.0 === $percent) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.pl:l:0');

            return;
        }
        $changes = $this->bulkChanges($percent);
        $applied = $this->priceAdjustment->apply($changes);
        $this->screen->audit($request, 'plan.bulk_price_adjust', 'Plan', null, ['percent' => $percent, 'field' => 'all', 'applied' => $applied]);
        $this->screen->notice($request, 'admin.plans.bulk_done', ['changes' => $applied], 'admin.pl:l:0');
    }

    /**
     * @return list<array{plan: Plan, field: string, before: int, after: int}>
     */
    private function bulkChanges(float $percent): array
    {
        return $this->priceAdjustment->preview($percent > 0 ? 'increase' : 'decrease', 'all', abs($percent), null)['changes'];
    }

    private function trialClaims(AdminRequest $request, int $page): void
    {
        $repository = $this->entityManager->getRepository(TrialClaim::class);
        [$page, $pages] = $this->screen->page($page, $repository->count([]));
        /** @var list<TrialClaim> $claims */
        $claims = $repository->findBy([], ['id' => 'DESC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);

        $lines = [$this->screen->text('admin.trial_claims.title', ['page_info' => $this->screen->pageInfo($page, $pages)])];
        foreach ($claims as $claim) {
            $account = $claim->getTelegramAccount();
            $lines[] = $this->screen->text('admin.trial_claims.line', [
                'id' => (int) $claim->getId(),
                'status' => $claim->getStatus(),
                'plan' => $claim->getTrialPlan()->getTitle(),
                'user' => sprintf('@%s / %s', $account->getUsername() ?: '-', $account->getTelegramId()),
                'date' => $claim->getCreatedAt()->format('Y-m-d H:i'),
            ]);
        }
        if ([] === $claims) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $this->screen->send($request, implode("\n", $lines), $this->screen->listKeyboard([], $this->screen->pagerRow($page, $pages, 'admin.pl:tc:%d')));
    }

    /**
     * @return array<string, string|int>
     */
    private function variables(Plan $plan): array
    {
        return [
            'id' => (int) $plan->getId(),
            'title' => $plan->getTitle(),
            'price' => AdminScreen::money($plan->getPrice()),
            'state' => $plan->isActive() ? '✅' : '⏸',
        ];
    }
}
