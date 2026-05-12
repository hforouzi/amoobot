<?php

declare(strict_types=1);

namespace App\Entity;

use App\Shop\Domain\OrderDraftStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OrderDraft
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(length: 50)]
    private string $status = OrderDraftStatus::PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customUsernamePrefix = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $finalUsername = null;

    #[ORM\Column(nullable: true)]
    private ?int $trafficGb = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $calculatedAmount = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $discountCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $discountAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $finalAmount = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $priceSnapshot = null;

    #[ORM\Column(length: 64)]
    private string $step = 'waiting_custom_username';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+1 hour');
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

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

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

    public function getCustomUsernamePrefix(): ?string
    {
        return $this->customUsernamePrefix;
    }

    public function setCustomUsernamePrefix(?string $customUsernamePrefix): self
    {
        $this->customUsernamePrefix = $customUsernamePrefix;

        return $this;
    }

    public function getFinalUsername(): ?string
    {
        return $this->finalUsername;
    }

    public function setFinalUsername(?string $finalUsername): self
    {
        $this->finalUsername = $finalUsername;

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

    public function getDurationDays(): ?int
    {
        return $this->durationDays;
    }

    public function setDurationDays(?int $durationDays): self
    {
        $this->durationDays = $durationDays;

        return $this;
    }

    public function getCalculatedAmount(): ?int
    {
        return $this->calculatedAmount;
    }

    public function setCalculatedAmount(?int $calculatedAmount): self
    {
        $this->calculatedAmount = $calculatedAmount;

        return $this;
    }

    public function getDiscountCode(): ?string
    {
        return $this->discountCode;
    }

    public function setDiscountCode(?string $discountCode): self
    {
        $this->discountCode = $discountCode;

        return $this;
    }

    public function getDiscountAmount(): ?int
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(?int $discountAmount): self
    {
        $this->discountAmount = $discountAmount;

        return $this;
    }

    public function getFinalAmount(): ?int
    {
        return $this->finalAmount;
    }

    public function setFinalAmount(?int $finalAmount): self
    {
        $this->finalAmount = $finalAmount;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPriceSnapshot(): ?array
    {
        return $this->priceSnapshot;
    }

    /**
     * @param array<string, mixed>|null $priceSnapshot
     */
    public function setPriceSnapshot(?array $priceSnapshot): self
    {
        $this->priceSnapshot = $priceSnapshot;

        return $this;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function setStep(string $step): self
    {
        $this->step = $step;

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): self
    {
        $this->data = $data;

        return $this;
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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
