<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One state-changing checkout call from the Mini App, by its client-generated Idempotency-Key: the second call with
 * the same key (double tap, retry after a lost answer) gets the first one's result instead of a second order or
 * payment. The unique (user, key) row is inserted before anything else happens (CheckoutIdempotency).
 */
#[ORM\Entity]
#[ORM\Table(name: 'checkout_request')]
#[ORM\UniqueConstraint(name: 'uniq_checkout_request_user_key', columns: ['user_id', 'idempotency_key'])]
class CheckoutRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey;

    /** What the key was used for (e.g. "new_service", "pay:12"): the same key for something else is refused. */
    #[ORM\Column(length: 64)]
    private string $scope;

    /** @var array<string, mixed>|null the answer given to the first call */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $idempotencyKey, string $scope, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->idempotencyKey = $idempotencyKey;
        $this->scope = $scope;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function setResult(array $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
