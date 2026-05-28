<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\RequiredChannel;

final class MembershipGateResult
{
    /**
     * @param list<RequiredChannel> $missingChannels
     * @param list<string>          $failedChecks
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $missingChannels = [],
        public readonly array $failedChecks = [],
    ) {
    }

    public static function allowed(): self
    {
        return new self(true);
    }
}
