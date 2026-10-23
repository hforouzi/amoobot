<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * Why a reseller action was refused, as a stable error code (the bot and the admin pages word it). Nothing was written
 * when it is thrown.
 */
final class ResellerException extends \RuntimeException
{
    public function __construct(public readonly string $error)
    {
        parent::__construct($error);
    }
}
