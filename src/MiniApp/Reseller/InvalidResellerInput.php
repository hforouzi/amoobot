<?php

declare(strict_types=1);

namespace App\MiniApp\Reseller;

/**
 * A reseller screen's input did not match its exact pattern (ResellerInput); nothing was read or written.
 */
final class InvalidResellerInput extends \RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('invalid '.$field);
    }
}
