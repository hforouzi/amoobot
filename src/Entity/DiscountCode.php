<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Domain\OrderType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discount_code', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_discount_code_code', columns: ['code'])])]
class DiscountCode
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    public const APPLIES_ALL = 'all';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_PERCENT;

    #[ORM\Column]
    private int $value = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column]
    private int $usedCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $maxUsesPerUser = null;

    #[ORM\Column]
    private bool $firstPurchaseOnly = false;

    #[ORM\Column(length: 30)]
    private string $appliesTo = self::APPLIES_ALL;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Plan $plan = null;

    #[ORM\Column(nullable: true)]
    private ?int $minAmount = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->code = '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

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

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): self
    {
        $this->value = $value;

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

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): self
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function setUsedCount(int $usedCount): self
    {
        $this->usedCount = max(0, $usedCount);

        return $this;
    }

    public function incrementUsedCount(): self
    {
        ++$this->usedCount;

        return $this;
    }

    public function getMaxUsesPerUser(): ?int
    {
        return $this->maxUsesPerUser;
    }

    public function setMaxUsesPerUser(?int $maxUsesPerUser): self
    {
        $this->maxUsesPerUser = $maxUsesPerUser;

        return $this;
    }

    public function isFirstPurchaseOnly(): bool
    {
        return $this->firstPurchaseOnly;
    }

    public function setFirstPurchaseOnly(bool $firstPurchaseOnly): self
    {
        $this->firstPurchaseOnly = $firstPurchaseOnly;

        return $this;
    }

    public function getAppliesTo(): string
    {
        return $this->appliesTo;
    }

    public function setAppliesTo(string $appliesTo): self
    {
        $this->appliesTo = $appliesTo;

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): self
    {
        $this->plan = $plan;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function allowedAppliesTo(): array
    {
        return [self::APPLIES_ALL, OrderType::NEW_SERVICE, OrderType::RENEWAL, OrderType::ADD_TRAFFIC];
    }

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return [self::TYPE_PERCENT, self::TYPE_FIXED];
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->code ?: '-', $this->title ?: 'Discount');
    }
}
