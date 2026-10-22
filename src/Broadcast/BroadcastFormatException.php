<?php

declare(strict_types=1);

namespace App\Broadcast;

/** A broadcast whose text Telegram would refuse in its parse mode was to be prepared or sent. */
final class BroadcastFormatException extends \DomainException
{
    public function __construct(public readonly BroadcastFormatProblem $problem)
    {
        parent::__construct('The broadcast text does not parse in its format: '.$problem->message);
    }
}
