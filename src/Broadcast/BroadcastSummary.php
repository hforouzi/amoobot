<?php

declare(strict_types=1);

namespace App\Broadcast;

final readonly class BroadcastSummary
{
    public function __construct(
        public int $prepared = 0,
        public int $pending = 0,
        public int $sent = 0,
        public int $failed = 0,
        public int $skipped = 0,
        public int $duplicates = 0,
        public int $broadcasts = 0,
    ) {
    }

    public function merge(self $summary): self
    {
        return new self(
            $this->prepared + $summary->prepared,
            $this->pending + $summary->pending,
            $this->sent + $summary->sent,
            $this->failed + $summary->failed,
            $this->skipped + $summary->skipped,
            $this->duplicates + $summary->duplicates,
            $this->broadcasts + $summary->broadcasts,
        );
    }

    public function toArray(): array
    {
        return [
            'broadcasts' => $this->broadcasts,
            'prepared' => $this->prepared,
            'pending' => $this->pending,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'duplicates' => $this->duplicates,
        ];
    }
}
