<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;

/**
 * What an admin is warned about for a plan with additional inbounds (docs/design/multi-inbound-plans.md 6); none of it
 * blocks saving (PlanInboundsValidator does that):
 *
 * - "not sold", with the reason, when the sale rule (MultiInboundSale::blocker(), PlanSaleRule) keeps it from sale;
 * - an additional inbound that became inactive since it was chosen;
 * - several protocols together: a mix that needs the real-panel check (design 7.3) before customers rely on it.
 *
 * Messages are keys of the admin domain with their parameters.
 */
final class PlanInboundWarnings
{
    /**
     * @return list<array{key: string, parameters: array<string, string>}>
     */
    public static function of(Plan $plan, bool $multiInboundEnabled): array
    {
        $additional = $plan->getAdditionalInbounds();
        if ([] === $additional) {
            return [];
        }
        $warnings = [];
        $blocker = $plan->multiInboundSaleBlocker($multiInboundEnabled);
        if (null !== $blocker) {
            $warnings[] = ['key' => 'plan_inbounds.not_sold.'.$blocker, 'parameters' => []];
        }
        foreach ($additional as $inbound) {
            if (!$inbound->isActive()) {
                $warnings[] = ['key' => 'plan_inbounds.warning.inactive', 'parameters' => ['%inbound%' => sprintf('#%d %s', (int) $inbound->getId(), $inbound->getTitle())]];
            }
        }
        $protocols = array_unique(array_map(PlanInboundsValidator::protocol(...), $plan->getInbounds()));
        if (count($protocols) > 1) {
            $warnings[] = ['key' => 'plan_inbounds.warning.mixed', 'parameters' => ['%protocols%' => implode(', ', $protocols)]];
        }

        return $warnings;
    }

    /**
     * After a plan's (or trial plan's) inbounds changed: its existing services that stay on another inbound
     * (PlanInboundDrift, docs/design/plan-inbound-change.md 3.3 (a)). Saving never moves them; this says so. Nothing
     * when there are none.
     *
     * @return list<array{key: string, parameters: array<string, string|int>}>
     */
    public static function drift(string $planTitle, string $newInbound, PlanInboundDriftCount $drift, string $breakdown, string $key = 'plan_inbound_drift.plan_saved'): array
    {
        if ($drift->total <= 0) {
            return [];
        }

        return [['key' => $key, 'parameters' => [
            '%plan%' => $planTitle,
            '%new%' => $newInbound,
            '%count%' => $drift->total,
            '%breakdown%' => $breakdown,
        ]]];
    }
}
