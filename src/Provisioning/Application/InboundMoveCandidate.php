<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * One service of a plan as a move run sees it (docs/design/plan-inbound-change.md 5.2): its id, current inbound, the
 * target, and either "move" or the skip reason (InboundMovePlanner::SKIP_*). panelNote is what a dry run read on the
 * panel (GET only), null when it did not read. Ids, titles and fixed texts only: never a link, UUID, subscription id,
 * client email, owner or token.
 */
final readonly class InboundMoveCandidate
{
    public const ACTION_MOVE = 'move';
    public const ACTION_SKIP = 'skip';

    public function __construct(
        public int $serviceId,
        public string $status,
        public ?int $fromInboundId,
        public ?string $fromRemoteInboundId,
        public string $fromTitle,
        public string $action,
        public ?string $skipReason = null,
        public ?string $skipText = null,
        public ?string $panelNote = null,
    ) {
    }

    public function withPanelNote(string $note): self
    {
        return new self($this->serviceId, $this->status, $this->fromInboundId, $this->fromRemoteInboundId, $this->fromTitle, $this->action, $this->skipReason, $this->skipText, $note);
    }

    public function skipped(string $reason, string $text): self
    {
        return new self($this->serviceId, $this->status, $this->fromInboundId, $this->fromRemoteInboundId, $this->fromTitle, self::ACTION_SKIP, $reason, $text, $this->panelNote);
    }

    /**
     * @return array{service_id: int, status: string, from_inbound_id: int|null, from_inbound: string, action: string, skip_reason: string|null, reason: string|null, panel: string|null}
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'status' => $this->status,
            'from_inbound_id' => $this->fromInboundId,
            'from_inbound' => $this->fromTitle,
            'action' => $this->action,
            'skip_reason' => $this->skipReason,
            'reason' => $this->skipText,
            'panel' => $this->panelNote,
        ];
    }
}
