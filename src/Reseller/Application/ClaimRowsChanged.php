<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use Doctrine\DBAL\Exception\RetryableException;

/**
 * The claim rows a claim-link write locked are no longer the ones it must lock (a link was made or used between its
 * plain read and its locks). Rolled back and run again from the start by ClaimTransactions, like a deadlock, so the
 * rows are always locked in the one order (by primary key, before the service row).
 */
final class ClaimRowsChanged extends \RuntimeException implements RetryableException
{
}
