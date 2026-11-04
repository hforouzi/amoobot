<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One service's move within an InboundMoveRun (docs/design/plan-inbound-change.md 4.3, 5.4, 5.6; PR P3: schema only).
 * Nothing creates, reads or processes these rows yet: the runner comes in P4/P6.
 *
 * - state: STATE_*, the 4.3 machine planned → attaching → switched → detaching → done, plus failed, skipped, undone,
 *   cancelled; skipReason holds the reason code when skipped (4.4, 6.2);
 * - fromInbound / fromRemoteInboundId: the service's primary A when the row was written (6.2: each item records its
 *   own A). The remote id is a snapshot so A can still be detached or undone after the local inbound was deleted or
 *   re-synced (4.4); it is the panel's inbound id only, never the service's remote id string (that carries the subId);
 * - toInbound / toRemoteInboundId: the target B, frozen the same way;
 * - the retry columns have the names and meaning of vpn_service_inbound (5.4): attempts, nextAttemptAt, alertState,
 *   alertedAt; claimedAt for the stale-claim takeover; lastError is masked (VpnServiceInbound::safeError());
 * - detachAfter: when the old inbound is due to be removed (NULL with keep-old); detachedAt: when it was (5.6).
 *
 * One move per service per run (unique run_id, service_id). Removed with its run (and so with its plan) or its service
 * (ON DELETE CASCADE). An inbound reference becomes NULL when that inbound is deleted, so a move row never blocks
 * deleting an inbound; the remote ids keep what is needed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_inbound_move')]
#[ORM\UniqueConstraint(name: 'uniq_service_inbound_move_run_service', columns: ['run_id', 'service_id'])]
#[ORM\Index(name: 'idx_service_inbound_move_due', columns: ['state', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_service_inbound_move_detach', columns: ['state', 'detach_after'])]
class ServiceInboundMove
{
    public const STATE_PLANNED = 'planned';
    public const STATE_ATTACHING = 'attaching';
    public const STATE_SWITCHED = 'switched';
    public const STATE_DETACHING = 'detaching';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_UNDONE = 'undone';
    public const STATE_CANCELLED = 'cancelled';
    public const STATES = [
        self::STATE_PLANNED, self::STATE_ATTACHING, self::STATE_SWITCHED, self::STATE_DETACHING, self::STATE_DONE,
        self::STATE_FAILED, self::STATE_SKIPPED, self::STATE_UNDONE, self::STATE_CANCELLED,
    ];
    /** Claimed by a process; taken over when claimedAt is older than ServiceInboundAttacher::STALE_CLAIM (5.4). */
    public const CLAIMED_STATES = [self::STATE_ATTACHING, self::STATE_DETACHING];
    /** Nothing more happens to the row. */
    public const FINAL_STATES = [self::STATE_DONE, self::STATE_FAILED, self::STATE_SKIPPED, self::STATE_UNDONE, self::STATE_CANCELLED];

    public const ALERT_FAILED = VpnServiceInbound::ALERT_FAILED;
    public const ALERT_EXHAUSTED = VpnServiceInbound::ALERT_EXHAUSTED;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InboundMoveRun $run;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VpnService $service;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnInbound $fromInbound;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fromRemoteInboundId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnInbound $toInbound;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $toRemoteInboundId;

    #[ORM\Column(length: 16)]
    private string $state = self::STATE_PLANNED;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $skipReason = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $detachAfter = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $detachedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $alertState = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $alertedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(InboundMoveRun $run, VpnService $service, ?VpnInbound $fromInbound, ?VpnInbound $toInbound, \DateTimeImmutable $now)
    {
        $this->run = $run;
        $this->service = $service;
        $this->fromInbound = $fromInbound;
        $this->fromRemoteInboundId = $fromInbound?->getRemoteInboundId();
        $this->toInbound = $toInbound;
        $this->toRemoteInboundId = $toInbound?->getRemoteInboundId();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): InboundMoveRun
    {
        return $this->run;
    }

    public function getService(): VpnService
    {
        return $this->service;
    }

    public function getFromInbound(): ?VpnInbound
    {
        return $this->fromInbound;
    }

    public function getFromRemoteInboundId(): ?string
    {
        return $this->fromRemoteInboundId;
    }

    public function getToInbound(): ?VpnInbound
    {
        return $this->toInbound;
    }

    public function getToRemoteInboundId(): ?string
    {
        return $this->toRemoteInboundId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state, \DateTimeImmutable $now): self
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown inbound move state "%s".', $state));
        }
        $this->state = $state;
        $this->updatedAt = $now;

        return $this;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }

    public function setSkipReason(?string $skipReason): self
    {
        $this->skipReason = $skipReason;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeImmutable $claimedAt): self
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function setNextAttemptAt(?\DateTimeImmutable $nextAttemptAt): self
    {
        $this->nextAttemptAt = $nextAttemptAt;

        return $this;
    }

    public function getDetachAfter(): ?\DateTimeImmutable
    {
        return $this->detachAfter;
    }

    public function setDetachAfter(?\DateTimeImmutable $detachAfter): self
    {
        $this->detachAfter = $detachAfter;

        return $this;
    }

    public function getDetachedAt(): ?\DateTimeImmutable
    {
        return $this->detachedAt;
    }

    public function setDetachedAt(?\DateTimeImmutable $detachedAt): self
    {
        $this->detachedAt = $detachedAt;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Stored masked and cut to the column, as vpn_service_inbound.last_error. */
    public function setLastError(?string $lastError): self
    {
        $this->lastError = null === $lastError ? null : VpnServiceInbound::safeError($lastError);

        return $this;
    }

    public function getAlertState(): ?string
    {
        return $this->alertState;
    }

    public function getAlertedAt(): ?\DateTimeImmutable
    {
        return $this->alertedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
