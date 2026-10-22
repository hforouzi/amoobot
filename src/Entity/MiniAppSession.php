<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A Telegram Mini App login (MiniAppSessionManager): opened by validated initData, identified by a random bearer token
 * of which only the SHA-256 is stored, bound to the Telegram user and the user agent, expiring, revocable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mini_app_session')]
#[ORM\UniqueConstraint(name: 'uniq_mini_app_session_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_mini_app_session_telegram_user', columns: ['telegram_user_id'])]
class MiniAppSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(length: 32)]
    private string $telegramUserId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $userAgentHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $tokenHash, string $telegramUserId, string $userAgentHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        $this->tokenHash = $tokenHash;
        $this->telegramUserId = $telegramUserId;
        $this->userAgentHash = $userAgentHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTelegramUserId(): string
    {
        return $this->telegramUserId;
    }

    public function getUserAgentHash(): string
    {
        return $this->userAgentHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): self
    {
        $this->revokedAt ??= $at;

        return $this;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now;
    }
}
