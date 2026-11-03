<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

/**
 * The existing services of one plan that are not on the inbound the plan now sells (PlanInboundDrift,
 * docs/design/plan-inbound-change.md 3.1). Counts only: never a customer, a reseller or a link.
 */
final readonly class PlanInboundDriftCount
{
    /**
     * @param list<array{id: int|null, title: string|null, count: int}> $byInbound the inbounds the services are on (null: the inbound was deleted locally), most services first
     * @param array<string, int>                                         $byStatus  active / suspended / expired => count
     * @param int|null                                                   $additionalMissing services missing one of the plan's additional inbounds (null: switch off, not counted)
     * @param int|null                                                   $additionalExtra   services still holding an inbound the plan no longer has (null: switch off)
     */
    public function __construct(
        public int $planId,
        public ?int $primaryId,
        public int $total,
        public array $byInbound,
        public array $byStatus,
        public int $otherPanel,
        public ?int $additionalMissing = null,
        public ?int $additionalExtra = null,
    ) {
    }

    public static function none(int $planId, ?int $primaryId): self
    {
        return new self($planId, $primaryId, 0, [], [], 0);
    }
}
