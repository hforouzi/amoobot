<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One change of a service's owner (docs/design/reseller-and-claim.md, 1.6): append-only, never updated or deleted. A
 * customer claimed it with a link (actor customer, with the claim), or an admin moved it back to the reseller who sold
 * it ("Return to reseller", actor admin, with the reason; also in the admin audit log). The ownership history for
 * support, and what the claims reconcile checks the current owner against.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'service_ownership_event')]
#[ORM\Index(name: 'idx_service_ownership_event_service', columns: ['service_id', 'id'])]
class ServiceOwnershipEvent
{
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private VpnService $service;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $fromUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $toUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceClaim $claim = null;

    #[ORM\Column(length: 16)]
    private string $actorType;

    #[ORM\Column(nullable: true)]
    private ?int $actorAdminId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(VpnService $service, User $fromUser, User $toUser, string $actorType, \DateTimeImmutable $createdAt)
    {
        $this->service = $service;
        $this->fromUser = $fromUser;
        $this->toUser = $toUser;
        $this->actorType = $actorType;
        $this->createdAt = $createdAt;
    }

    public static function claimed(ServiceClaim $claim, User $fromUser, User $claimer, \DateTimeImmutable $at): self
    {
        $event = new self($claim->getService(), $fromUser, $claimer, self::ACTOR_CUSTOMER, $at);
        $event->claim = $claim;

        return $event;
    }

    public static function returnedByAdmin(VpnService $service, User $fromUser, User $toUser, ?int $adminUserId, string $reason, \DateTimeImmutable $at): self
    {
        $event = new self($service, $fromUser, $toUser, self::ACTOR_ADMIN, $at);
        $event->actorAdminId = $adminUserId;
        $event->reason = mb_substr($reason, 0, 255);

        return $event;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): VpnService
    {
        return $this->service;
    }

    public function getFromUser(): User
    {
        return $this->fromUser;
    }

    public function getToUser(): User
    {
        return $this->toUser;
    }

    public function getClaim(): ?ServiceClaim
    {
        return $this->claim;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorAdminId(): ?int
    {
        return $this->actorAdminId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
