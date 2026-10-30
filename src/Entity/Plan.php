<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Shop\Application\Catalog\PlanInbounds;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A plan on sale. Its rules (the constraints below) are checked the same way by the web form, the bot and the Mini App
 * (PlanAdminService); prices are only warned about, never blocked (PlanPriceSanityChecker).
 */
#[ORM\Entity]
#[PlanInbounds]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'plan.title.required')]
    #[Assert\Length(max: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $durationDays;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $trafficGb = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $ipLimit = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $price;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isCustomizable = false;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $minTrafficGb = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $maxTrafficGb = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $pricePerGb = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $minDurationDays = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $maxDurationDays = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $pricePerDay = null;

    #[ORM\Column]
    private bool $allowCustomUsername = true;

    #[ORM\Column]
    private bool $isUnlimitedDuration = false;

    /** The primary inbound (docs/design/multi-inbound-plans.md 2.1): every single-inbound reader keeps using it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnInbound $inbound = null;

    /**
     * The additional inbounds (plans.multi_inbound_enabled), in their order. EXTRA_LAZY: isAvailableForSale() asks
     * whether there are any with a COUNT, without loading them.
     *
     * @var Collection<int, PlanAdditionalInbound>
     */
    #[ORM\OneToMany(targetEntity: PlanAdditionalInbound::class, mappedBy: 'plan', cascade: ['persist'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $additionalInbounds;

    /**
     * Not stored: the additional inbounds this request added, and whether it changed them or the primary (PlanInbounds).
     *
     * @var list<VpnInbound>
     */
    private array $addedAdditionalInbounds = [];
    private bool $additionalInboundsChanged = false;
    private bool $primaryInboundChanged = false;

    /** The lowest wholesale price of this plan for any reseller (Toman), on top of the reseller.floor_bp rule; null = none. */
    #[ORM\Column(nullable: true)]
    private ?int $resellerFloorAmount = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->additionalInbounds = new ArrayCollection();
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
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): self
    {
        $this->durationDays = $durationDays;

        return $this;
    }

    public function getTrafficGb(): ?int
    {
        return $this->trafficGb;
    }

    public function setTrafficGb(?int $trafficGb): self
    {
        $this->trafficGb = $trafficGb;

        return $this;
    }

    public function getIpLimit(): ?int
    {
        return $this->ipLimit;
    }

    public function setIpLimit(?int $ipLimit): self
    {
        $this->ipLimit = $ipLimit;

        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Shown and sold to customers: active, and its inbound and panel usable (a degraded panel hides its plans).
     *
     * A plan with additional inbounds is never sold yet (fail closed, phase M1): provisioning attaches the primary only,
     * so a customer would get fewer servers than the plan promises. This holds whatever plans.multi_inbound_enabled says.
     */
    public function isAvailableForSale(): bool
    {
        if (!$this->isActive) {
            return false;
        }
        if ($this->hasAdditionalInbounds()) {
            return false;
        }
        if (null === $this->inbound) {
            return true;
        }

        return $this->inbound->isActive() && $this->inbound->getPanel()->isAvailableForSale();
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isCustomizable(): bool
    {
        return $this->isCustomizable;
    }

    public function setIsCustomizable(bool $isCustomizable): self
    {
        $this->isCustomizable = $isCustomizable;

        return $this;
    }

    public function getMinTrafficGb(): ?int
    {
        return $this->minTrafficGb;
    }

    public function setMinTrafficGb(?int $minTrafficGb): self
    {
        $this->minTrafficGb = $minTrafficGb;

        return $this;
    }

    public function getMaxTrafficGb(): ?int
    {
        return $this->maxTrafficGb;
    }

    public function setMaxTrafficGb(?int $maxTrafficGb): self
    {
        $this->maxTrafficGb = $maxTrafficGb;

        return $this;
    }

    public function getPricePerGb(): ?int
    {
        return $this->pricePerGb;
    }

    public function setPricePerGb(?int $pricePerGb): self
    {
        $this->pricePerGb = $pricePerGb;

        return $this;
    }

    public function getMinDurationDays(): ?int
    {
        return $this->minDurationDays;
    }

    public function setMinDurationDays(?int $minDurationDays): self
    {
        $this->minDurationDays = $minDurationDays;

        return $this;
    }

    public function getMaxDurationDays(): ?int
    {
        return $this->maxDurationDays;
    }

    public function setMaxDurationDays(?int $maxDurationDays): self
    {
        $this->maxDurationDays = $maxDurationDays;

        return $this;
    }

    public function getPricePerDay(): ?int
    {
        return $this->pricePerDay;
    }

    public function setPricePerDay(?int $pricePerDay): self
    {
        $this->pricePerDay = $pricePerDay;

        return $this;
    }

    public function isAllowCustomUsername(): bool
    {
        return $this->allowCustomUsername;
    }

    public function setAllowCustomUsername(bool $allowCustomUsername): self
    {
        $this->allowCustomUsername = $allowCustomUsername;

        return $this;
    }

    public function isUnlimitedDuration(): bool
    {
        return $this->isUnlimitedDuration;
    }

    public function setIsUnlimitedDuration(bool $isUnlimitedDuration): self
    {
        $this->isUnlimitedDuration = $isUnlimitedDuration;

        return $this;
    }

    public function isFixedDurationCustomPlan(): bool
    {
        return null !== $this->minDurationDays
            && null !== $this->maxDurationDays
            && $this->minDurationDays === $this->maxDurationDays;
    }

    public function calculateCustomPrice(int $trafficGb, int $durationDays): int
    {
        $pricePerGb = max(0, (int) ($this->pricePerGb ?? 0));
        $pricePerDay = max(0, (int) ($this->pricePerDay ?? 0));

        if (0 === $pricePerGb && 0 === $pricePerDay) {
            return 0;
        }
        if (0 === $pricePerDay) {
            return $trafficGb * $pricePerGb;
        }
        if (0 === $pricePerGb) {
            return $durationDays * $pricePerDay;
        }

        return ($trafficGb * $pricePerGb) + ($durationDays * $pricePerDay);
    }

    /**
     * A limited plan lasts at least a day; a range's minimum is not above its maximum; a customizable plan has a
     * price per GB or per day (else every custom choice would cost 0).
     */
    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (!$this->isUnlimitedDuration && $this->durationDays < 1) {
            $context->buildViolation('plan.duration.required')->atPath('durationDays')->addViolation();
        }
        if (null !== $this->minTrafficGb && null !== $this->maxTrafficGb && $this->minTrafficGb > $this->maxTrafficGb) {
            $context->buildViolation('plan.range.order')->atPath('maxTrafficGb')->addViolation();
        }
        if (null !== $this->minDurationDays && null !== $this->maxDurationDays && $this->minDurationDays > $this->maxDurationDays) {
            $context->buildViolation('plan.range.order')->atPath('maxDurationDays')->addViolation();
        }
        if ($this->isCustomizable && ($this->pricePerGb ?? 0) <= 0 && ($this->pricePerDay ?? 0) <= 0) {
            $context->buildViolation('plan.custom.price_required')->atPath('pricePerGb')->addViolation();
        }
    }

    public function getInbound(): ?VpnInbound
    {
        return $this->inbound;
    }

    public function setInbound(?VpnInbound $inbound): self
    {
        $this->primaryInboundChanged = $this->primaryInboundChanged || $inbound !== $this->inbound;
        $this->inbound = $inbound;

        return $this;
    }

    /** Whether the plan has additional inbounds (a COUNT when they are not loaded). */
    public function hasAdditionalInbounds(): bool
    {
        return !$this->additionalInbounds->isEmpty();
    }

    /**
     * The additional inbounds, in their order (never the primary).
     *
     * @return list<VpnInbound>
     */
    public function getAdditionalInbounds(): array
    {
        return array_map(static fn (PlanAdditionalInbound $row): VpnInbound => $row->getInbound(), $this->additionalInbounds->getValues());
    }

    /**
     * Replaces the additional inbounds with $inbounds, in that order: rows of inbounds that stay are kept (only their
     * order changes), the others are removed, new ones added. Repeats are kept once. Whether the list is allowed is
     * PlanInboundRules' business (PlanAdminService::save()).
     *
     * @param iterable<VpnInbound> $inbounds
     */
    public function setAdditionalInbounds(iterable $inbounds): self
    {
        $wanted = [];
        foreach ($inbounds as $inbound) {
            $wanted[spl_object_id($inbound)] ??= $inbound;
        }
        $kept = [];
        foreach ($this->additionalInbounds->toArray() as $row) {
            if (isset($wanted[spl_object_id($row->getInbound())])) {
                $kept[spl_object_id($row->getInbound())] = $row;
            } else {
                $this->additionalInbounds->removeElement($row);
                $this->additionalInboundsChanged = true;
            }
        }
        $now = new \DateTimeImmutable();
        $order = 0;
        foreach ($wanted as $key => $inbound) {
            ++$order;
            if (isset($kept[$key])) {
                $this->additionalInboundsChanged = $this->additionalInboundsChanged || $kept[$key]->getSortOrder() !== $order;
                $kept[$key]->setSortOrder($order);
            } else {
                $this->additionalInbounds->add(new PlanAdditionalInbound($this, $inbound, $order, $now));
                $this->addedAdditionalInbounds[] = $inbound;
                $this->additionalInboundsChanged = true;
            }
        }

        return $this;
    }

    /**
     * The web form's "additional inbounds" field (not the Doctrine association: EasyAdmin would treat that as a collection
     * of rows).
     *
     * @return list<VpnInbound>
     */
    public function getAdditionalInboundList(): array
    {
        return $this->getAdditionalInbounds();
    }

    /**
     * @param iterable<VpnInbound> $inbounds
     */
    public function setAdditionalInboundList(iterable $inbounds): self
    {
        return $this->setAdditionalInbounds($inbounds);
    }

    /** Whether this request changed the additional inbounds (added, removed or reordered). */
    public function additionalInboundsChanged(): bool
    {
        return $this->additionalInboundsChanged;
    }

    /**
     * The additional inbounds this request added (the rules for a newly chosen inbound are stricter than for a kept one:
     * a kept one may have become inactive since, which is a warning).
     *
     * @return list<VpnInbound>
     */
    public function addedAdditionalInbounds(): array
    {
        return $this->addedAdditionalInbounds;
    }

    /** Whether this request set another primary inbound. */
    public function primaryInboundChanged(): bool
    {
        return $this->primaryInboundChanged;
    }

    /**
     * Every inbound of the plan: the primary first, then the additional ones by their order (docs/design/
     * multi-inbound-plans.md 2.1).
     *
     * @return list<VpnInbound>
     */
    public function getInbounds(): array
    {
        return [...(null === $this->inbound ? [] : [$this->inbound]), ...$this->getAdditionalInbounds()];
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
        return sprintf('%s (%d)', $this->title ?? 'Plan', $this->id ?? 0);
    }

    public function getResellerFloorAmount(): ?int
    {
        return $this->resellerFloorAmount;
    }

    public function setResellerFloorAmount(?int $resellerFloorAmount): self
    {
        $this->resellerFloorAmount = null === $resellerFloorAmount || $resellerFloorAmount <= 0 ? null : $resellerFloorAmount;

        return $this;
    }
}
