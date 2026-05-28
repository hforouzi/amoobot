<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['is_active', 'sort_order', 'id'], name: 'idx_trial_plan_active_sort')]
class TrialPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private int $durationDays = 1;

    #[ORM\Column(nullable: true)]
    private ?int $trafficGb = null;

    #[ORM\Column(nullable: true)]
    private ?int $ipLimit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnInbound $inbound = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Plan $backingPlan = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxClaimsTotal = null;

    #[ORM\Column]
    private int $maxClaimsPerUser = 1;

    #[ORM\Column(nullable: true)]
    private ?int $cooldownHours = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $value = trim((string) ($description ?? ''));
        $this->description = '' === $value ? null : $value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): self
    {
        $this->durationDays = max(1, $durationDays);

        return $this;
    }

    public function getTrafficGb(): ?int
    {
        return $this->trafficGb;
    }

    public function setTrafficGb(?int $trafficGb): self
    {
        $this->trafficGb = null === $trafficGb ? null : max(0, $trafficGb);

        return $this;
    }

    public function getIpLimit(): ?int
    {
        return $this->ipLimit;
    }

    public function setIpLimit(?int $ipLimit): self
    {
        $this->ipLimit = null === $ipLimit ? null : max(0, $ipLimit);

        return $this;
    }

    public function getInbound(): ?VpnInbound
    {
        return $this->inbound;
    }

    public function setInbound(?VpnInbound $inbound): self
    {
        $this->inbound = $inbound;

        return $this;
    }

    public function getBackingPlan(): ?Plan
    {
        return $this->backingPlan;
    }

    public function setBackingPlan(?Plan $backingPlan): self
    {
        $this->backingPlan = $backingPlan;

        return $this;
    }

    public function getMaxClaimsTotal(): ?int
    {
        return $this->maxClaimsTotal;
    }

    public function setMaxClaimsTotal(?int $maxClaimsTotal): self
    {
        $this->maxClaimsTotal = null === $maxClaimsTotal ? null : max(0, $maxClaimsTotal);

        return $this;
    }

    public function getMaxClaimsPerUser(): int
    {
        return $this->maxClaimsPerUser;
    }

    public function setMaxClaimsPerUser(int $maxClaimsPerUser): self
    {
        $this->maxClaimsPerUser = max(1, $maxClaimsPerUser);

        return $this;
    }

    public function getCooldownHours(): ?int
    {
        return $this->cooldownHours;
    }

    public function setCooldownHours(?int $cooldownHours): self
    {
        $this->cooldownHours = null === $cooldownHours ? null : max(0, $cooldownHours);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s (%d)', $this->title ?? 'Trial Plan', $this->id ?? 0);
    }
}
