<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

/**
 * A plan that orders refer to cannot be deleted (the database keeps those orders' plan); deactivate it instead.
 */
final class PlanInUse extends \RuntimeException
{
    public function __construct(public readonly int $orderCount)
    {
        parent::__construct(sprintf('The plan has %d order(s) and cannot be deleted.', $orderCount));
    }
}
