<?php

declare(strict_types=1);

namespace App\Entity;

use App\Broadcast\BroadcastFormat;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
class BroadcastMessage
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    /** Stopped by BroadcastSender: Telegram could not parse the text (metadata stopReason "format_error"). */
    public const STATUS_STOPPED = 'stopped';

    public const TARGET_ALL = 'all';
    public const TARGET_ACTIVE_USERS = 'active_users';
    public const TARGET_USERS_WITH_ACTIVE_SERVICE = 'users_with_active_service';
    public const TARGET_USERS_WITH_EXPIRING_SERVICE = 'users_with_expiring_service';
    public const TARGET_SELECTED_USERS = 'selected_users';
    public const TARGET_ADMINS = 'admins';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_STOPPED,
    ];

    public const TARGET_TYPES = [
        self::TARGET_ALL,
        self::TARGET_ACTIVE_USERS,
        self::TARGET_USERS_WITH_ACTIVE_SERVICE,
        self::TARGET_USERS_WITH_EXPIRING_SERVICE,
        self::TARGET_SELECTED_USERS,
        self::TARGET_ADMINS,
    ];

    public const PARSE_MODES = ['HTML', 'MarkdownV2'];
    public const TELEGRAM_MESSAGE_LIMIT = 4096;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TELEGRAM_MESSAGE_LIMIT)]
    private string $body = '';

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 64)]
    #[Assert\Choice(choices: self::TARGET_TYPES)]
    private string $targetType = self::TARGET_ALL;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $targetUserIds = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: self::PARSE_MODES)]
    private ?string $parseMode = null;

    #[ORM\Column]
    private bool $disableWebPagePreview = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sendAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $totalRecipients = 0;

    #[ORM\Column]
    private int $sentCount = 0;

    #[ORM\Column]
    private int $failedCount = 0;

    #[ORM\Column]
    private int $skippedCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

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

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetUserIds(): ?array
    {
        return $this->targetUserIds;
    }

    public function setTargetUserIds(?array $targetUserIds): self
    {
        $this->targetUserIds = $targetUserIds;

        return $this;
    }

    public function getParseMode(): ?string
    {
        return $this->parseMode;
    }

    public function setParseMode(?string $parseMode): self
    {
        $parseMode = null === $parseMode ? null : trim($parseMode);
        $this->parseMode = '' === $parseMode ? null : $parseMode;

        return $this;
    }

    public function isDisableWebPagePreview(): bool
    {
        return $this->disableWebPagePreview;
    }

    public function setDisableWebPagePreview(bool $disableWebPagePreview): self
    {
        $this->disableWebPagePreview = $disableWebPagePreview;

        return $this;
    }

    public function getSendAt(): ?\DateTimeImmutable
    {
        return $this->sendAt;
    }

    public function setSendAt(?\DateTimeImmutable $sendAt): self
    {
        $this->sendAt = $sendAt;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getTotalRecipients(): int
    {
        return $this->totalRecipients;
    }

    public function setTotalRecipients(int $totalRecipients): self
    {
        $this->totalRecipients = max(0, $totalRecipients);

        return $this;
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function setSentCount(int $sentCount): self
    {
        $this->sentCount = max(0, $sentCount);

        return $this;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function setFailedCount(int $failedCount): self
    {
        $this->failedCount = max(0, $failedCount);

        return $this;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function setSkippedCount(int $skippedCount): self
    {
        $this->skippedCount = max(0, $skippedCount);

        return $this;
    }

    public function getCreatedBy(): ?AdminUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?AdminUser $createdBy): self
    {
        $this->createdBy = $createdBy;

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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** Telegram's formatting rules for the chosen parse mode (BroadcastFormat), on the body. */
    #[Assert\Callback]
    public function validateFormat(ExecutionContextInterface $context): void
    {
        $problem = BroadcastFormat::problem($this->body, $this->parseMode);
        if (null !== $problem) {
            $context->buildViolation($problem->message, $problem->parameters)->atPath('body')->addViolation();
        }
    }

    public function isEditable(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function __toString(): string
    {
        return sprintf('%s #%d', $this->title ?: 'Broadcast', $this->id ?? 0);
    }
}
