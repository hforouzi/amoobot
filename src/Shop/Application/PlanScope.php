<?php

declare(strict_types=1);

namespace App\Shop\Application;

/**
 * Which plans a bulk price adjustment covers (PlanPriceAdjustmentService::preview): by default every plan, as the
 * web panel and the bot do; the Mini App can limit it to the active plans and / or a selection.
 */
final readonly class PlanScope
{
    /**
     * @param list<int>|null $planIds only these plans; null: no selection
     */
    public function __construct(
        public bool $activeOnly = false,
        public ?array $planIds = null,
    ) {
    }
}
