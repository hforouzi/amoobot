<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\Plan;
use App\Shop\Application\PlanPriceAdjustmentService;
use App\Shop\Application\PlanScope;
use App\Tests\Support\IntegrationTestCase;

/**
 * PlanPriceAdjustmentService: every plan by default (web panel, bot), or the active plans / a selection (Mini App);
 * the change is the floor of the percentage and never goes below 0.
 */
final class PlanPriceAdjustmentScopeTest extends IntegrationTestCase
{
    public function testScopeAndRounding(): void
    {
        $service = self::getContainer()->get(PlanPriceAdjustmentService::class);
        $active = $this->createPlan(99999);
        $inactive = $this->createPlan(200000)->setIsActive(false);
        $other = $this->createPlan(150000)->setPricePerGb(3000);
        $this->em->flush();
        $ids = static fn (array $preview): array => array_values(array_unique(array_map(static fn (array $c): int => (int) $c['plan']->getId(), $preview['changes'])));

        self::assertSame([(int) $active->getId(), (int) $inactive->getId(), (int) $other->getId()], $ids($service->preview('increase', 'price', 10.0, null)), 'default: every plan');
        self::assertSame([(int) $active->getId(), (int) $other->getId()], $ids($service->preview('increase', 'price', 10.0, null, new PlanScope(activeOnly: true))));
        self::assertSame([(int) $other->getId()], $ids($service->preview('increase', 'price', 10.0, null, new PlanScope(planIds: [(int) $other->getId()]))));
        self::assertSame([], $service->preview('increase', 'price', 10.0, null, new PlanScope(planIds: []))['changes'], 'an empty selection changes nothing');

        $change = $service->preview('increase', 'price', 10.0, null, new PlanScope(planIds: [(int) $active->getId()]))['changes'][0];
        self::assertSame([99999, 109998], [$change['before'], $change['after']], 'floor(9999.9) = 9999');
        self::assertSame(0, $service->preview('decrease', 'price', 150.0, null, new PlanScope(planIds: [(int) $active->getId()]))['changes'][0]['after']);

        $all = $service->preview('increase', 'all', 10.0, null, new PlanScope(planIds: [(int) $other->getId()]));
        self::assertSame(['price', 'pricePerGb'], array_column($all['changes'], 'field'));
        self::assertSame(2, $service->apply($all['changes']));
        $this->em->clear();
        $reloaded = $this->em->find(Plan::class, $other->getId());
        self::assertSame([165000, 3300], [$reloaded?->getPrice(), $reloaded?->getPricePerGb()]);
    }
}
