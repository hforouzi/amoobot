<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discount_usage', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_discount_usage_order_code_user', columns: ['order_id', 'discount_code_id', 'user_id'])])]
class DiscountUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DiscountCode $discountCode;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column]
    private int $amountBefore = 0;

    #[ORM\Column]
    private int $discountAmount = 0;

    #[ORM\Column]
    private int $amountAfter = 0;

    #[ORM\Column]
    private \DateTimeImmutable $usedAt;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDiscountCode(): DiscountCode
    {
        return $this->discountCode;
    }

    public function setDiscountCode(DiscountCode $discountCode): self
    {
        $this->discountCode = $discountCode;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getAmountBefore(): int
    {
        return $this->amountBefore;
    }

    public function setAmountBefore(int $amountBefore): self
    {
        $this->amountBefore = max(0, $amountBefore);

        return $this;
    }

    public function getDiscountAmount(): int
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(int $discountAmount): self
    {
        $this->discountAmount = max(0, $discountAmount);

        return $this;
    }

    public function getAmountAfter(): int
    {
        return $this->amountAfter;
    }

    public function setAmountAfter(int $amountAfter): self
    {
        $this->amountAfter = max(0, $amountAfter);

        return $this;
    }

    public function getUsedAt(): \DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(\DateTimeImmutable $usedAt): self
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('Usage #%d', $this->id ?? 0);
    }
}
