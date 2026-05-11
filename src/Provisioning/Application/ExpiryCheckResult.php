<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class ExpiryCheckResult
{
    public function __construct(
        public readonly int $serviceId,
        public readonly string $outcome,
        public readonly bool $statusChanged = false,
        public readonly ?string $message = null,
    ) {
    }

    public function isUpdated(): bool
    {
        return 'updated' === $this->outcome;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->outcome;
    }

    public function isSkipped(): bool
    {
        return 'skipped' === $this->outcome;
    }
}
