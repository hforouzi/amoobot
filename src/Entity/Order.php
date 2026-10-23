<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: '`order`')]
#[ORM\Index(name: 'idx_order_reseller', columns: ['reseller_id', 'status', 'created_at'])]
class Order
{
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

    public function setMetadata(?array $metadata): self
    {
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
