<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StorePaymentMethod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PaymentGateway $gateway;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(nullable: true)]
    private ?int $minAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxAmount = null;

    #[ORM\Column(length: 8)]
    private string $currency = 'IRR';

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

    public function getGateway(): PaymentGateway
    {
        return $this->gateway;
    }

    public function setGateway(PaymentGateway $gateway): self
    {
        $this->gateway = $gateway;
        if ('' === trim($this->title)) {
            $this->title = $gateway->getTitle();
        }
        if ('' === trim($this->currency)) {
            $this->currency = $gateway->getCurrency();
        }

        return $this;
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

    public function getMinAmount(): ?int
    {
        return $this->minAmount;
    }

    public function setMinAmount(?int $minAmount): self
    {
        $this->minAmount = $minAmount;

        return $this;
    }

    public function getMaxAmount(): ?int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(?int $maxAmount): self
    {
        $this->maxAmount = $maxAmount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        $this->currency = '' === $currency ? 'IRR' : $currency;

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

    public function isAmountAllowed(int $amount): bool
    {
        if (null !== $this->minAmount && $amount < $this->minAmount) {
            return false;
        }

        if (null !== $this->maxAmount && $amount > $this->maxAmount) {
            return false;
        }

        return true;
    }

    public function __toString(): string
    {
        return sprintf('%s -> %s', $this->title ?: 'Store Payment Method', $this->gateway->getTitle());
    }
}

