<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

/**
 * What deleting a trial plan did with its backing plan (TrialPlanAdminService::delete()), for the admin's message and
 * the audit:
 * - BACKING_DELETED: nothing referred to it any more, it was deleted with the trial plan;
 * - BACKING_KEPT: orders refer to it (each trial claim made one; their services and claims hang off them), so it stays,
 *   inactive and hidden (TrialBackingPlans knows it by those trial orders), and is never sold;
 * - NO_BACKING: the trial plan was never claimed (no backing plan yet);
 * - ALREADY_GONE: the trial plan had been deleted meanwhile (nothing done).
 */
final class TrialPlanDeletion
{
    public const BACKING_DELETED = 'backing_deleted';
    public const BACKING_KEPT = 'backing_kept';
    public const NO_BACKING = 'no_backing';
    public const ALREADY_GONE = 'already_gone';

    /**
     * @param list<string> $deactivatedDiscountCodes codes limited to the deleted backing plan, deactivated rather than
     *                                               widened to every plan (discount_code.plan_id is ON DELETE SET NULL)
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $backingPlanId = null,
        public readonly ?string $backingPlanTitle = null,
        public readonly int $orders = 0,
        public readonly int $services = 0,
        public readonly int $claims = 0,
        public readonly array $deactivatedDiscountCodes = [],
    ) {
    }

    /**
     * @return array<string, mixed> for the audit log
     */
    public function auditDetails(): array
    {
        return array_filter([
            'backingPlan' => $this->outcome,
            'backingPlanId' => $this->backingPlanId,
            'orders' => $this->orders,
            'services' => $this->services,
            'claims' => $this->claims,
            'deactivatedDiscountCodes' => $this->deactivatedDiscountCodes,
        ], static fn (mixed $v): bool => null !== $v && [] !== $v);
    }
}
