<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_broadcast_delivery_chat', columns: ['broadcast_id', 'chat_id'])]
class BroadcastDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BroadcastMessage $broadcast;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TelegramAccount $telegramAccount = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'chat_id', length: 255)]
    #[Assert\NotBlank]
    private string $chatId = '';

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $telegramMessageId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

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

    public function getBroadcast(): BroadcastMessage
    {
        return $this->broadcast;
    }

    public function setBroadcast(BroadcastMessage $broadcast): self
    {
        $this->broadcast = $broadcast;

        return $this;
    }

    public function getTelegramAccount(): ?TelegramAccount
    {
        return $this->telegramAccount;
    }

    public function setTelegramAccount(?TelegramAccount $telegramAccount): self
    {
        $this->telegramAccount = $telegramAccount;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTelegramMessageId(): ?string
    {
        return $this->telegramMessageId;
    }

    public function setTelegramMessageId(null|int|string $telegramMessageId): self
    {
        $this->telegramMessageId = null === $telegramMessageId ? null : (string) $telegramMessageId;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = null === $errorMessage ? null : substr($errorMessage, 0, 4000);

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

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
        return sprintf('%s: %s', $this->chatId, $this->status);
    }
}
