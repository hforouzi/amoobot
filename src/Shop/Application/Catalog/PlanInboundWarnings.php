<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;

/**
 * What an admin is warned about for a plan with additional inbounds (docs/design/multi-inbound-plans.md 6); none of it
 * blocks saving (PlanInboundsValidator does that):
 *
 * - "not sold yet": it takes effect when provisioning support ships; until then the plan is not sold at all;
 * - an additional inbound that became inactive since it was chosen;
 * - the panel not in modern mode (any more);
 * - several protocols together: a mix that needs the real-panel check (design 7.3) before customers rely on it.
 *
 * Messages are keys of the admin domain with their parameters.
 */
final class PlanInboundWarnings
{
    /**
     * @return list<array{key: string, parameters: array<string, string>}>
     */
    public static function of(Plan $plan): array
    {
        $additional = $plan->getAdditionalInbounds();
        if ([] === $additional) {
            return [];
        }
        $warnings = [['key' => 'plan_inbounds.warning.not_sold', 'parameters' => []]];
        foreach ($additional as $inbound) {
            if (!$inbound->isActive()) {
                $warnings[] = ['key' => 'plan_inbounds.warning.inactive', 'parameters' => ['%inbound%' => sprintf('#%d %s', (int) $inbound->getId(), $inbound->getTitle())]];
            }
        }
        $panel = $plan->getInbound()?->getPanel();
        if (null !== $panel && ('sanaei_3xui' !== $panel->getType() || !$panel->isModern())) {
            $warnings[] = ['key' => 'plan_inbounds.warning.not_modern', 'parameters' => []];
        }
        $protocols = array_unique(array_map(PlanInboundsValidator::protocol(...), $plan->getInbounds()));
        if (count($protocols) > 1) {
            $warnings[] = ['key' => 'plan_inbounds.warning.mixed', 'parameters' => ['%protocols%' => implode(', ', $protocols)]];
        }

        return $warnings;
    }
}
