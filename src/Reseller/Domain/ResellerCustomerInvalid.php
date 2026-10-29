<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * A customer label's values were refused (ResellerCustomers); nothing was written. $errors: codes by field
 * (required, too_long, spaces, characters, format, limit_reached).
 */
final class ResellerCustomerInvalid extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('invalid customer label: '.implode(', ', array_keys($errors)));
    }
}
