<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reseller's own wholesale rule for one plan, instead of the reseller's default discount: a discount in basis
 * points, or (fixed plans only) a fixed price. Exactly one of the two is set (ResellerAdministration checks it).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reseller_plan_price')]
#[ORM\UniqueConstraint(name: 'uniq_reseller_plan_price', columns: ['reseller_id', 'plan_id'])]
class ResellerPlanPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Reseller $reseller;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Plan $plan;

    #[ORM\Column(nullable: true)]
    private ?int $discountBp = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $fixedAmount = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?int $updatedByAdminId = null;

    public function __construct(Reseller $reseller, Plan $plan)
    {
        $this->reseller = $reseller;
        $this->plan = $plan;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReseller(): Reseller
    {
        return $this->reseller;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getDiscountBp(): ?int
    {
        return $this->discountBp;
    }

    public function getFixedAmount(): ?int
    {
        return $this->fixedAmount;
    }

    /** A percent rule (clears the fixed price). */
    public function usePercent(int $discountBp, ?int $adminId): self
    {
        $this->discountBp = $discountBp;
        $this->fixedAmount = null;

        return $this->touched($adminId);
    }

    /** A fixed-price rule (clears the percent). */
    public function useFixedAmount(int $fixedAmount, ?int $adminId): self
    {
        $this->fixedAmount = $fixedAmount;
        $this->discountBp = null;

        return $this->touched($adminId);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedByAdminId(): ?int
    {
        return $this->updatedByAdminId;
    }

    private function touched(?int $adminId): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedByAdminId = $adminId;

        return $this;
    }
}
