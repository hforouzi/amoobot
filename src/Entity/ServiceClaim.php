<?php

declare(strict_types=1);

namespace App\Entity;

use App\Reseller\Domain\ClaimStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A claim link (docs/design/reseller-and-claim.md, 1.5 / 3): a single-use, expiring link that moves one service from
 * its owner (fromUser: the reseller's account, or whoever owned it when an admin made the link) to the Telegram user
 * who opens it and confirms. Only the SHA-256 of its token is stored. Written only by ClaimLinks (create, revoke),
 * ClaimService (claim), ClaimMaintenance (expiry, panel sync) and ResellerAdministration (revoked with a disable).
 *
 * open_service_id equals service_id while the link is open and is NULL otherwise: its unique key allows at most one
 * open link per service (MySQL / MariaDB have no partial index).
 *
 * The panel_sync_* columns track the best-effort update of the panel client (tgId, comment) after the service changed
 * owner: pending until it worked (synced), was not possible (skipped: legacy panel, no remote id) or was given up after
 * too many attempts (failed). The database stays the source of truth for ownership whatever the panel says.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_claim')]
#[ORM\UniqueConstraint(name: 'uniq_service_claim_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_service_claim_open_service', columns: ['open_service_id'])]
#[ORM\Index(name: 'idx_service_claim_reseller', columns: ['reseller_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_service_claim_status_expires', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_service_claim_panel_sync', columns: ['panel_sync_status', 'panel_sync_next_at'])]
class ServiceClaim
{
    public const SYNC_PENDING = 'pending';
    public const SYNC_SYNCED = 'synced';
    public const SYNC_SKIPPED = 'skipped';
    public const SYNC_FAILED = 'failed';

    public const REVOKED_BY_RESELLER = 'reseller';
    public const REVOKED_BY_ADMIN = 'admin';
    public const REVOKED_REPLACED = 'replaced';
    public const REVOKED_RESELLER_DISABLED = 'reseller_disabled';
    public const REVOKED_RETURNED = 'returned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private VpnService $service;

    /** The reseller whose link it is; null for a link an admin made. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Reseller $reseller = null;

    /** The owner the service moves away from (checked again at claim time: the link dies if the owner changed). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $fromUser;

    #[ORM\Column(nullable: true)]
    private ?int $createdByUserId = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdByAdminId = null;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(length: 16)]
    private string $status = ClaimStatus::OPEN;

    #[ORM\Column(nullable: true)]
    private ?int $openServiceId = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $claimedByUser = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $claimedByTelegramId = null;

    #[ORM\Column(nullable: true)]
    private ?int $revokedByUserId = null;

    #[ORM\Column(nullable: true)]
    private ?int $revokedByAdminId = null;

    /** Why it was revoked (REVOKED_*). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $revokeReason = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $panelSyncStatus = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $panelSyncAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $panelSyncNextAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $panelSyncedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $panelSyncError = null;

    public function __construct(VpnService $service, User $fromUser, string $tokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $tokenHash)) {
            throw new \InvalidArgumentException('A claim stores the SHA-256 hex of its token, never the token.');
        }
        $this->service = $service;
        $this->fromUser = $fromUser;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->openServiceId = $service->getId();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): VpnService
    {
        return $this->service;
    }

    public function getReseller(): ?Reseller
    {
        return $this->reseller;
    }

    public function getFromUser(): User
    {
        return $this->fromUser;
    }

    public function getCreatedByUserId(): ?int
    {
        return $this->createdByUserId;
    }

    public function getCreatedByAdminId(): ?int
    {
        return $this->createdByAdminId;
    }

    /** Made by a reseller (for his own service). */
    public function byReseller(Reseller $reseller): self
    {
        $this->reseller = $reseller;
        $this->createdByUserId = $reseller->getUser()->getId();
        $this->createdByAdminId = null;

        return $this;
    }

    /** Made by an admin (support case), for whoever owns the service now (no reseller: it is not his link). */
    public function byAdmin(?int $adminUserId): self
    {
        $this->reseller = null;
        $this->createdByAdminId = $adminUserId;
        $this->createdByUserId = null;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return ClaimStatus::OPEN === $this->status;
    }

    public function getOpenServiceId(): ?int
    {
        return $this->openServiceId;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function getClaimedByUser(): ?User
    {
        return $this->claimedByUser;
    }

    public function getClaimedByTelegramId(): ?string
    {
        return $this->claimedByTelegramId;
    }

    public function getRevokedByUserId(): ?int
    {
        return $this->revokedByUserId;
    }

    public function getRevokedByAdminId(): ?int
    {
        return $this->revokedByAdminId;
    }

    public function getRevokeReason(): ?string
    {
        return $this->revokeReason;
    }

    /** @internal ClaimService, under the claim and service row locks */
    public function markClaimed(User $claimer, string $telegramId, \DateTimeImmutable $now): self
    {
        $this->assertOpen();
        $this->status = ClaimStatus::CLAIMED;
        $this->openServiceId = null;
        $this->claimedAt = $now;
        $this->claimedByUser = $claimer;
        $this->claimedByTelegramId = $telegramId;
        $this->panelSyncStatus = self::SYNC_PENDING;
        $this->panelSyncAttempts = 0;
        $this->panelSyncNextAt = null;

        return $this;
    }

    /** @internal ClaimLinks / ResellerAdministration, under the claim row lock */
    public function markRevoked(string $reason, ?int $byUserId, ?int $byAdminId, \DateTimeImmutable $now): self
    {
        $this->assertOpen();
        $this->status = ClaimStatus::REVOKED;
        $this->openServiceId = null;
        $this->revokedAt = $now;
        $this->revokeReason = $reason;
        $this->revokedByUserId = $byUserId;
        $this->revokedByAdminId = $byAdminId;

        return $this;
    }

    /** @internal ClaimService (found expired at claim time), under the claim row lock */
    public function markExpired(\DateTimeImmutable $now): self
    {
        $this->assertOpen();
        $this->status = ClaimStatus::EXPIRED;
        $this->openServiceId = null;
        $this->expiredAt = $now;

        return $this;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function getPanelSyncStatus(): ?string
    {
        return $this->panelSyncStatus;
    }

    public function getPanelSyncAttempts(): int
    {
        return $this->panelSyncAttempts;
    }

    public function getPanelSyncNextAt(): ?\DateTimeImmutable
    {
        return $this->panelSyncNextAt;
    }

    public function getPanelSyncedAt(): ?\DateTimeImmutable
    {
        return $this->panelSyncedAt;
    }

    public function getPanelSyncError(): ?string
    {
        return $this->panelSyncError;
    }

    /** The panel must be told about the current owner again (after a claim, or after an admin returned the service). */
    public function requestPanelSync(): self
    {
        $this->panelSyncStatus = self::SYNC_PENDING;
        $this->panelSyncAttempts = 0;
        $this->panelSyncNextAt = null;
        $this->panelSyncError = null;

        return $this;
    }

    public function panelSynced(\DateTimeImmutable $now): self
    {
        $this->panelSyncStatus = self::SYNC_SYNCED;
        $this->panelSyncedAt = $now;
        $this->panelSyncNextAt = null;
        $this->panelSyncError = null;

        return $this;
    }

    public function panelSyncSkipped(string $why): self
    {
        $this->panelSyncStatus = self::SYNC_SKIPPED;
        $this->panelSyncNextAt = null;
        $this->panelSyncError = mb_substr($why, 0, 255);

        return $this;
    }

    /**
     * One more failed attempt: tried again at $nextAt, or given up (failed) when $nextAt is null.
     */
    public function panelSyncFailed(string $error, ?\DateTimeImmutable $nextAt): self
    {
        ++$this->panelSyncAttempts;
        $this->panelSyncStatus = null === $nextAt ? self::SYNC_FAILED : self::SYNC_PENDING;
        $this->panelSyncNextAt = $nextAt;
        $this->panelSyncError = mb_substr($error, 0, 255);

        return $this;
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(sprintf('Claim #%d is %s, not open.', (int) $this->id, $this->status));
        }
    }
}
