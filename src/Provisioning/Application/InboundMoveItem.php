<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * One service handled by a runner pass (console line, JSON): ids, inbound titles, what happened and a safe text.
 * panelRead: true = the panel answered, false = no read of the panel succeeded (counted towards the automatic pause),
 * null = the panel was not asked.
 */
final readonly class InboundMoveItem
{
    public const SWITCHED = 'switched';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    /** Claimed by another runner meanwhile, or no longer due: nothing done. */
    public const BUSY = 'busy';
    /** Switched earlier; only its links were refreshed now. */
    public const SNAPSHOT = 'snapshot';
    /** The client's UUID, subId or remote id changed between the claim and the write (a link reset): nothing written. */
    public const IDENTITY_CHANGED = 'identity_changed';

    public function __construct(
        public int $moveId,
        public int $serviceId,
        public string $from,
        public string $to,
        public string $outcome,
        public ?string $text = null,
        public ?bool $panelRead = null,
        public bool $attachSent = false,
        public ?string $snapshot = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'move_id' => $this->moveId,
            'service_id' => $this->serviceId,
            'from_inbound' => $this->from,
            'to_inbound' => $this->to,
            'outcome' => $this->outcome,
            'text' => $this->text,
            'attach_sent' => $this->attachSent,
            'snapshot' => $this->snapshot,
        ];
    }
}
