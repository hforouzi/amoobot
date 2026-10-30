<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use Symfony\Component\Validator\Constraint;

/**
 * A plan's additional inbounds keep the rules of docs/design/multi-inbound-plans.md 2.1 and D1 (PlanInboundsValidator).
 * On the Plan class, so the web form, the bot and the Mini App (PlanAdminService::save()) all check them.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PlanInbounds extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
