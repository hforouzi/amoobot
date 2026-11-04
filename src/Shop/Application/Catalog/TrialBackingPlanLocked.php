<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

/**
 * A sales-side change to a trial's backing plan (TrialBackingPlans), refused: it is managed from its trial plan.
 * Nothing was written.
 */
final class TrialBackingPlanLocked extends \DomainException
{
    public function __construct(public readonly int $planId)
    {
        parent::__construct(sprintf('Plan #%d belongs to a trial plan; it is managed from the trial plans.', $planId));
    }
}
