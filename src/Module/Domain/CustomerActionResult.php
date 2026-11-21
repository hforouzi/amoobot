<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * What running a customer action returned to its caller (2.2's ActionResult). PM1d: only whether it failed. The
 * messages an action sends to the customer are sent by the module itself, as before; an exception is not caught.
 */
final readonly class CustomerActionResult
{
    private function __construct(public bool $failed)
    {
    }

    public static function done(): self
    {
        return new self(false);
    }

    public static function failed(): self
    {
        return new self(true);
    }
}
