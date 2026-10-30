<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: '`order`')]
#[ORM\Index(name: 'idx_order_reseller', columns: ['reseller_id', 'status', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_order_reseller_nonce', columns: ['reseller_id', 'reseller_nonce'])]
class Order
{
    /**
     * metadata key: the plan's inbounds when the order was created (docs/design/multi-inbound-plans.md 3.1, M2c):
     * ['primary' => inbound id|null, 'additional' => [inbound ids, in order]]. planInboundSnapshot().
     */
    public const PLAN_INBOUNDS = 'planInbounds';
    /** metadata key: additional inbounds of the snapshot that no longer existed at provisioning (their ids). */
    public const PLAN_INBOUNDS_MISSING = 'planInboundsMissing';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null only for an order that buys no plan (WALLET_TOPUP). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Plan $plan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnService $targetService = null;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 50)]
    private string $type = OrderType::NEW_SERVICE;

    #[ORM\Column(length: 50)]
    private string $status = OrderStatus::PENDING;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $trackingCode = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    /**
     * The reseller who bought this order at wholesale (new service, renewal, add traffic), or null for a retail order.
     * Such an order is paid from the reseller's wallet only (ResellerCheckout); no gateway is ever offered for it.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Reseller $reseller = null;

    /** Reseller orders: what the reseller pays (= amount), the retail value and the floor at that moment (snapshot). */
    #[ORM\Column(nullable: true)]
    private ?int $wholesaleAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $retailAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $resellerFloorAmount = null;

    /** The one-time confirmation this reseller order was created from (ResellerConfirmations); unique per reseller. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $resellerNonce = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /** The order's plan; null only for an order that buys no plan (WALLET_TOPUP). */
    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    /**
     * The plan of a service order (new service, renewal, add traffic, trial: always set).
     *
     * @throws \LogicException for an order without a plan (a wallet top-up)
     */
    public function requirePlan(): Plan
    {
        return $this->plan ?? throw new \LogicException(sprintf('Order #%d (%s) has no plan.', $this->id ?? 0, $this->type));
    }

    public function hasPlan(): bool
    {
        return null !== $this->plan;
    }

    public function isWalletTopUp(): bool
    {
        return OrderType::WALLET_TOPUP === $this->type;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

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

    public function getTargetService(): ?VpnService
    {
        return $this->targetService;
    }

    public function setTargetService(?VpnService $targetService): self
    {
        $this->targetService = $targetService;

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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Replaces the metadata. The plan's inbound snapshot (PLAN_INBOUNDS) is kept when $metadata does not carry it: it
     * describes what was sold and is never dropped by a caller that rewrites the other keys.
     */
    public function setMetadata(?array $metadata): self
    {
        if (isset($this->metadata[self::PLAN_INBOUNDS]) && !isset($metadata[self::PLAN_INBOUNDS])) {
            $metadata = ($metadata ?? []) + [self::PLAN_INBOUNDS => $this->metadata[self::PLAN_INBOUNDS]];
        }
        $this->metadata = $metadata;

        return $this;
    }

    public function getTrackingCode(): ?string
    {
        return $this->trackingCode;
    }

    public function setTrackingCode(?string $trackingCode): self
    {
        $trackingCode = null === $trackingCode ? null : strtoupper(trim($trackingCode));
        $this->trackingCode = '' === (string) $trackingCode ? null : $trackingCode;

        return $this;
    }

    /**
     * A new-service order remembers its plan's inbounds when it is created, so a plan edited before the order is paid
     * or provisioned does not change what this order gets (docs/design/multi-inbound-plans.md 3.1, M2c). Skipped when
     * an id is not known yet (an inbound or plan persisted in the same flush), and for orders that have one already.
     */
    #[ORM\PrePersist]
    public function snapshotPlanInbounds(): void
    {
        if (OrderType::NEW_SERVICE !== $this->type || null === $this->plan || isset($this->metadata[self::PLAN_INBOUNDS])) {
            return;
        }
        $primary = $this->plan->getInbound();
        $additional = array_map(static fn (VpnInbound $inbound): ?int => $inbound->getId(), $this->plan->getAdditionalInbounds());
        if ((null !== $primary && null === $primary->getId()) || in_array(null, $additional, true)) {
            return;
        }
        $this->metadata = ($this->metadata ?? []) + [self::PLAN_INBOUNDS => ['primary' => $primary?->getId(), 'additional' => $additional]];
    }

    /**
     * The plan's inbounds when this order was created, or null for an order without a snapshot (created before M2c,
     * or not a new service): the caller then uses the live plan.
     *
     * @return array{primary: ?int, additional: list<int>}|null
     */
    public function planInboundSnapshot(): ?array
    {
        $snapshot = $this->metadata[self::PLAN_INBOUNDS] ?? null;
        if (!is_array($snapshot) || !is_array($snapshot['additional'] ?? null)) {
            return null;
        }
        $primary = $snapshot['primary'] ?? null;

        return [
            'primary' => is_int($primary) && $primary > 0 ? $primary : null,
            'additional' => array_values(array_unique(array_filter(array_map('intval', array_filter($snapshot['additional'], 'is_numeric')), static fn (int $id): bool => $id > 0))),
        ];
    }

    public function isCustomOrder(): bool
    {
        return true === ($this->metadata['custom'] ?? false);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getProvisionedAt(): ?\DateTimeImmutable
    {
        return $this->provisionedAt;
    }

    public function setProvisionedAt(?\DateTimeImmutable $provisionedAt): self
    {
        $this->provisionedAt = $provisionedAt;

        return $this;
    }

    public function getReseller(): ?Reseller
    {
        return $this->reseller;
    }

    public function isResellerOrder(): bool
    {
        return null !== $this->reseller;
    }

    public function getWholesaleAmount(): ?int
    {
        return $this->wholesaleAmount;
    }

    public function getRetailAmount(): ?int
    {
        return $this->retailAmount;
    }

    public function getResellerFloorAmount(): ?int
    {
        return $this->resellerFloorAmount;
    }

    public function getResellerNonce(): ?string
    {
        return $this->resellerNonce;
    }

    /** ResellerCheckout only, before the order is persisted. */
    public function setResellerNonce(?string $resellerNonce): self
    {
        $this->resellerNonce = $resellerNonce;

        return $this;
    }

    /** Marks a new order as a reseller's, with its price snapshot (ResellerCheckout only, before it is persisted). */
    public function attributeToReseller(Reseller $reseller, int $wholesaleAmount, int $retailAmount, int $floorAmount): self
    {
        $this->reseller = $reseller;
        $this->wholesaleAmount = $wholesaleAmount;
        $this->retailAmount = $retailAmount;
        $this->resellerFloorAmount = $floorAmount;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('Order #%d', $this->id ?? 0);
    }
}
