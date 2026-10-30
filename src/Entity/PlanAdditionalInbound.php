<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An additional inbound of a plan (docs/design/multi-inbound-plans.md 2.1): a buyer's one client is meant to be attached
 * to the plan's primary inbound (plan.inbound) and to every additional one. Never the primary itself; same panel as the
 * primary (PlanInboundRules). Phase M1 only stores them: a plan with any is not sold until provisioning supports them.
 *
 * Removed with its plan (ON DELETE CASCADE: it never blocks deleting a plan). The inbound is RESTRICT: an inbound a plan
 * sells cannot be deleted (the admin is told which plans use it, VpnInboundCrudController).
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_additional_inbound')]
#[ORM\UniqueConstraint(name: 'uniq_plan_additional_inbound', columns: ['plan_id', 'inbound_id'])]
class PlanAdditionalInbound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'additionalInbounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private VpnInbound $inbound;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Plan $plan, VpnInbound $inbound, int $sortOrder, \DateTimeImmutable $now)
    {
        $this->plan = $plan;
        $this->inbound = $inbound;
        $this->sortOrder = $sortOrder;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getInbound(): VpnInbound
    {
        return $this->inbound;
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
}
