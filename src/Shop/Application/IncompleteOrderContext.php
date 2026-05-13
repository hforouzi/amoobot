<?php

declare(strict_types=1);

namespace App\Shop\Application;

final class IncompleteOrderContext
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
    }
}

