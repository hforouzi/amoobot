<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class RequiredChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $chatId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inviteUrl = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $requireForPurchase = true;

    #[ORM\Column]
    private bool $requireForTrial = true;

    #[ORM\Column]
    private int $sortOrder = 0;

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

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function setChatId(string $chatId): self
    {
        $this->chatId = trim($chatId);

        return $this;
    }

    public function getInviteUrl(): ?string
    {
        return $this->inviteUrl;
    }

    public function setInviteUrl(?string $inviteUrl): self
    {
        $value = trim((string) ($inviteUrl ?? ''));
        $this->inviteUrl = '' === $value ? null : $value;

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

    public function isRequireForPurchase(): bool
    {
        return $this->requireForPurchase;
    }

    public function setRequireForPurchase(bool $requireForPurchase): self
    {
        $this->requireForPurchase = $requireForPurchase;

        return $this;
    }

    public function isRequireForTrial(): bool
    {
        return $this->requireForTrial;
    }

    public function setRequireForTrial(bool $requireForTrial): self
    {
        $this->requireForTrial = $requireForTrial;

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
        return sprintf('%s (%s)', $this->title, $this->chatId);
    }
}
