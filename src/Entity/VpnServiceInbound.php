<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An additional inbound a service's client should be attached to on the panel (docs/design/multi-inbound-plans.md 2.2),
 * with the state of that attachment. The primary stays vpn_service.inbound / remoteId. Written by provisioning (one row
 * per additional inbound, attached or failed), "Retry attach" (ServiceInboundAttacher) and a panel delete (detached).
 *
 * Removed with its service (ON DELETE CASCADE: it never blocks deleting a service or the user it goes with). The inbound
 * is RESTRICT: an inbound a service is attached to cannot be deleted.
 *
 * M2b: the scheduled retry (InboundAttachScheduler) tries a failed row again at nextAttemptAt (NULL: due now) until its
 * attempts reach the limit, and compares the rows with the panel every few hours (checkedAt). alertState is the last
 * admin alert sent for the row (InboundAttachAlerts): ALERT_FAILED, ALERT_EXHAUSTED, or NULL (none, or it recovered).
 * These columns are written with SQL by those classes and ServiceInboundAttacher only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vpn_service_inbound')]
#[ORM\UniqueConstraint(name: 'uniq_vpn_service_inbound', columns: ['service_id', 'inbound_id'])]
#[ORM\Index(name: 'idx_vpn_service_inbound_due', columns: ['status', 'next_attempt_at'])]
class VpnServiceInbound
{
    public const STATUS_ATTACHED = 'attached';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DETACHED = 'detached';
    public const STATUSES = [self::STATUS_ATTACHED, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_DETACHED];

    public const ALERT_FAILED = 'failed';
    public const ALERT_EXHAUSTED = 'exhausted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VpnService $service;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private VpnInbound $inbound;

    /** The inbound's id on the panel when the row was written (a snapshot: the local inbound may be re-synced). */
    #[ORM\Column(length: 64)]
    private string $remoteInboundId;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attachedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $alertState = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $alertedAt = null;

    /** Re-attach cycles (removed on the panel, then attached again) since reattachSince, M2c F7; never reset by an attempt. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $reattachCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reattachSince = null;

    public function __construct(VpnService $service, VpnInbound $inbound, \DateTimeImmutable $now)
    {
        $this->service = $service;
        $this->inbound = $inbound;
        $this->remoteInboundId = $inbound->getRemoteInboundId();
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): VpnService
    {
        return $this->service;
    }

    public function getInbound(): VpnInbound
    {
        return $this->inbound;
    }

    public function getRemoteInboundId(): string
    {
        return $this->remoteInboundId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getAttachedAt(): ?\DateTimeImmutable
    {
        return $this->attachedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** When the scheduled retry may try a failed row again; NULL: at the next run (or not failed). */
    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getAlertState(): ?string
    {
        return $this->alertState;
    }

    public function getAlertedAt(): ?\DateTimeImmutable
    {
        return $this->alertedAt;
    }

    public function getReattachCount(): int
    {
        return $this->reattachCount;
    }

    public function getReattachSince(): ?\DateTimeImmutable
    {
        return $this->reattachSince;
    }

    /**
     * One attach attempt (at the sale): attached, or failed with a reason that is safe to show (ServiceErrorText). An
     * attached row keeps its first attachedAt. A failed one keeps its nextAttemptAt (NULL: the scheduled retry tries it
     * at its next run).
     */
    public function recordAttempt(bool $attached, ?string $error, \DateTimeImmutable $now): self
    {
        ++$this->attempts;
        $this->status = $attached ? self::STATUS_ATTACHED : self::STATUS_FAILED;
        $this->lastError = $attached || null === $error ? null : mb_substr($error, 0, 255);
        $this->attachedAt = $attached ? ($this->attachedAt ?? $now) : $this->attachedAt;
        $this->nextAttemptAt = $attached ? null : $this->nextAttemptAt;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * The outcome at the sale is not known (M2c F2: the panel could not be read back after the attach; F5: the attach
     * was not tried within the time budget): pending, with why; an attempt is counted only when the attach was sent. The
     * scheduled run takes a pending row over once its claim is stale (ServiceInboundAttacher::STALE_CLAIM) and either
     * finds it attached on the panel or attaches it.
     */
    public function recordPending(string $why, bool $tried, \DateTimeImmutable $now): self
    {
        $this->attempts += $tried ? 1 : 0;
        $this->status = self::STATUS_PENDING;
        $this->lastError = mb_substr($why, 0, 255);
        $this->nextAttemptAt = null;
        $this->updatedAt = $now;

        return $this;
    }

    /** The client was deleted on the panel (del/{email} removes it from every inbound). */
    public function markDetached(\DateTimeImmutable $now): self
    {
        $this->status = self::STATUS_DETACHED;
        $this->nextAttemptAt = null;
        $this->updatedAt = $now;

        return $this;
    }
}
