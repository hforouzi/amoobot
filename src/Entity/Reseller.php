<?php

declare(strict_types=1);

namespace App\Entity;

use App\Reseller\Domain\ResellerStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer account that may buy at wholesale from its own wallet (docs/design/reseller-and-claim.md, S1). Created by
 * an admin only (ResellerAdministration, RESELLERS_MANAGE); never deleted (orders and services point at it), switched
 * off instead. The credit limit is the wallet's own (wallet.credit_limit), not a column here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reseller')]
#[ORM\UniqueConstraint(name: 'uniq_reseller_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_reseller_status', columns: ['status'])]
class Reseller
{
    /** The highest discount an admin may give (90 %); the floor still applies on top. */
    public const MAX_DISCOUNT_BP = 9000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 16, options: ['default' => ResellerStatus::ACTIVE])]
    private string $status = ResellerStatus::ACTIVE;

    /** The default wholesale discount off a plan's base price, in basis points (0..MAX_DISCOUNT_BP). */
    #[ORM\Column(options: ['default' => 0])]
    private int $discountBp = 0;

    /** At most this much wholesale (Toman) per day, app timezone; null = no cap. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $dailySpendLimit = null;

    /** Shown to a customer who receives a service (later: claim links); never the reseller's Telegram data. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $createdByAdminId = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return ResellerStatus::ACTIVE === $this->status;
    }

    /** @internal ResellerAdministration only (it audits and revokes what a disable must revoke) */
    public function setStatus(string $status): self
    {
        if (!in_array($status, ResellerStatus::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reseller status "%s".', $status));
        }
        $this->status = $status;
        $this->disabledAt = ResellerStatus::DISABLED === $status ? new \DateTimeImmutable() : null;
        $this->touch();

        return $this;
    }

    public function getDiscountBp(): int
    {
        return $this->discountBp;
    }

    public function setDiscountBp(int $discountBp): self
    {
        if ($discountBp < 0 || $discountBp > self::MAX_DISCOUNT_BP) {
            throw new \InvalidArgumentException('The reseller discount must be 0..'.self::MAX_DISCOUNT_BP.' bp.');
        }
        $this->discountBp = $discountBp;
        $this->touch();

        return $this;
    }

    public function getDailySpendLimit(): ?int
    {
        return $this->dailySpendLimit;
    }

    public function setDailySpendLimit(?int $dailySpendLimit): self
    {
        if (null !== $dailySpendLimit && $dailySpendLimit <= 0) {
            throw new \InvalidArgumentException('The daily spend limit must be positive or null.');
        }
        $this->dailySpendLimit = $dailySpendLimit;
        $this->touch();

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $displayName = null === $displayName ? null : trim($displayName);
        $this->displayName = '' === $displayName ? null : $displayName;
        $this->touch();

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = null === $note ? null : trim($note);
        $this->note = '' === $note ? null : $note;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function getCreatedByAdminId(): ?int
    {
        return $this->createdByAdminId;
    }

    public function setCreatedByAdminId(?int $createdByAdminId): self
    {
        $this->createdByAdminId = $createdByAdminId;

        return $this;
    }

    public function __toString(): string
    {
        return $this->displayName ?? ($this->user->getName() ?? ('#'.$this->user->getId()));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
