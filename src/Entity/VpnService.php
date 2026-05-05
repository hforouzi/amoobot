<?php

declare(strict_types=1);

namespace App\Entity;

use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class VpnService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnPanel $panel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remoteId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subscriptionUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $configText = null;

    #[ORM\Column(length: 50)]
    private string $status = VpnServiceStatus::ACTIVE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $trafficLimitGb = null;

    #[ORM\Column(nullable: true)]
    private ?int $trafficUsedGb = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getPanel(): ?VpnPanel
    {
        return $this->panel;
    }

    public function setPanel(?VpnPanel $panel): self
    {
        $this->panel = $panel;

        return $this;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function setRemoteId(?string $remoteId): self
    {
        $this->remoteId = $remoteId;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getSubscriptionUrl(): ?string
    {
        return $this->subscriptionUrl;
    }

    public function setSubscriptionUrl(?string $subscriptionUrl): self
    {
        $this->subscriptionUrl = $subscriptionUrl;

        return $this;
    }

    public function getConfigText(): ?string
    {
        return $this->configText;
    }

    public function setConfigText(?string $configText): self
    {
        $this->configText = $configText;

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

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;

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

    public function getTrafficLimitGb(): ?int
    {
        return $this->trafficLimitGb;
    }

    public function setTrafficLimitGb(?int $trafficLimitGb): self
    {
        $this->trafficLimitGb = $trafficLimitGb;

        return $this;
    }

    public function getTrafficUsedGb(): ?int
    {
        return $this->trafficUsedGb;
    }

    public function setTrafficUsedGb(?int $trafficUsedGb): self
    {
        $this->trafficUsedGb = $trafficUsedGb;

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
}
