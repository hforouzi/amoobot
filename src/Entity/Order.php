<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: '`order`')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Plan $plan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnService $targetService = null;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 50)]
    private string $type = OrderType::NEW_SERVICE;

    #[ORM\Column(length: 50)]
    private string $status = OrderStatus::PENDING;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTargetService(): ?VpnService
    {
        return $this->targetService;
    }

    public function setTargetService(?VpnService $targetService): self
    {
        $this->targetService = $targetService;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function isCustomOrder(): bool
    {
        return true === ($this->metadata['custom'] ?? false);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getProvisionedAt(): ?\DateTimeImmutable
    {
        return $this->provisionedAt;
    }

    public function setProvisionedAt(?\DateTimeImmutable $provisionedAt): self
    {
        $this->provisionedAt = $provisionedAt;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('Order #%d', $this->id ?? 0);
    }
}
