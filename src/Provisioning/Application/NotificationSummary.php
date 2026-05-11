<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final class NotificationSummary
{
    public function __construct(
        public readonly int $checked,
        public readonly int $sent,
        public readonly int $skipped,
        public readonly int $failed,
    ) {
    }

    public function merge(NotificationSummary $other): self
    {
        return new self(
            checked: $this->checked + $other->checked,
            sent: $this->sent + $other->sent,
            skipped: $this->skipped + $other->skipped,
            failed: $this->failed + $other->failed,
        );
    }
}

