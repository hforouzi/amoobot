<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An additional inbound a service's client should be attached to on the panel (docs/design/multi-inbound-plans.md 2.2),
 * with the state of that attachment. The primary stays vpn_service.inbound / remoteId. Phase M1 creates the table only:
 * nothing writes rows before provisioning supports several inbounds (M2).
 *
 * Removed with its service (ON DELETE CASCADE: it never blocks deleting a service or the user it goes with). The inbound
 * is RESTRICT: an inbound a service is attached to cannot be deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vpn_service_inbound')]
#[ORM\UniqueConstraint(name: 'uniq_vpn_service_inbound', columns: ['service_id', 'inbound_id'])]
class VpnServiceInbound
{
    public const STATUS_ATTACHED = 'attached';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DETACHED = 'detached';
    public const STATUSES = [self::STATUS_ATTACHED, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_DETACHED];

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
}
