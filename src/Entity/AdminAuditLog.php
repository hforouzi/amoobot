<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One admin action (web, bot or CLI): who, what, on which record, and masked details. Written by AdminAuditLogger;
 * read-only in the admin (LOGS_VIEW).
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(name: 'idx_admin_audit_log_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_admin_audit_log_target', columns: ['target_type', 'target_id'])]
class AdminAuditLog
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_BOT = 'bot';
    public const SOURCE_CLI = 'cli';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** AdminUser id; null for the TELEGRAM_ADMIN_CHAT_ID bot admin or an unauthenticated CLI run. */
    #[ORM\Column(nullable: true)]
    private ?int $actorAdminUserId = null;

    #[ORM\Column(length: 190)]
    private string $actorLabel;

    #[ORM\Column(length: 8)]
    private string $source;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $targetId = null;

    /** @var array<string, mixed>|null secrets already masked */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(?int $actorAdminUserId, string $actorLabel, string $source, string $action, ?string $targetType, ?string $targetId, ?array $details)
    {
        $this->actorAdminUserId = $actorAdminUserId;
        $this->actorLabel = mb_substr($actorLabel, 0, 190);
        $this->source = $source;
        $this->action = mb_substr($action, 0, 64);
        $this->targetType = null === $targetType ? null : mb_substr($targetType, 0, 64);
        $this->targetId = null === $targetId ? null : mb_substr($targetId, 0, 64);
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActorAdminUserId(): ?int
    {
        return $this->actorAdminUserId;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return sprintf('%s #%d', $this->action, $this->id ?? 0);
    }
}
