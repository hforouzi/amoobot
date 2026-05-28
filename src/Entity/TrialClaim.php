<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['telegram_account_id', 'trial_plan_id', 'status'], name: 'idx_trial_claim_account_plan_status')]
#[ORM\Index(columns: ['trial_plan_id', 'status'], name: 'idx_trial_claim_plan_status')]
class TrialClaim
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONED = 'provisioned';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TelegramAccount $telegramAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrialPlan $trialPlan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VpnService $vpnService = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramAccount(): TelegramAccount
    {
        return $this->telegramAccount;
    }

    public function setTelegramAccount(TelegramAccount $telegramAccount): self
    {
        $this->telegramAccount = $telegramAccount;

        return $this;
    }

    public function getTrialPlan(): TrialPlan
    {
        return $this->trialPlan;
    }

    public function setTrialPlan(TrialPlan $trialPlan): self
    {
        $this->trialPlan = $trialPlan;

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

    public function getVpnService(): ?VpnService
    {
        return $this->vpnService;
    }

    public function setVpnService(?VpnService $vpnService): self
    {
        $this->vpnService = $vpnService;

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

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $value = trim((string) ($failureReason ?? ''));
        $this->failureReason = '' === $value ? null : $value;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function __toString(): string
    {
        return sprintf('Trial Claim #%d', $this->id ?? 0);
    }
}
