<?php

declare(strict_types=1);

namespace App\Subscription\Application;

/**
 * An upload that is not accepted; the message is a translation key (domain "admin") with the parameters below.
 */
final class AppDownloadRejected extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $reason, public readonly array $parameters = [])
    {
        parent::__construct($reason);
    }
}
