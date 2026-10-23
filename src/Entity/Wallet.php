<?php

declare(strict_types=1);

namespace App\Entity;

use App\Wallet\Domain\WalletStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's wallet: one per User, created lazily on first use (WalletService::walletFor()).
 *
 * $balance is a cache of the ledger (the sum of its WalletTransaction amounts), written only by WalletService under
 * a row lock, together with the ledger row; app:wallet:reconcile checks the two agree. Amounts are integer Toman, as
 * orders and payments store them. balance may go below zero down to -creditLimit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wallet')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_wallet_last_activity', columns: ['last_activity_at'])]
class Wallet
{
    /** Toman, the shop currency (AmountNormalizer::SHOP_CURRENCY). */
    public const CURRENCY = 'IRT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $balance = 0;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $creditLimit = 0;

    #[ORM\Column(length: 8, options: ['default' => self::CURRENCY])]
    private string $currency = self::CURRENCY;

    #[ORM\Column(length: 16, options: ['default' => WalletStatus::ACTIVE])]
    private string $status = WalletStatus::ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** The last ledger movement. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function getCreditLimit(): int
    {
        return $this->creditLimit;
    }

    /** What may still be spent: balance + credit limit. */
    public function getAvailable(): int
    {
        return $this->balance + $this->creditLimit;
    }

    /** How much of the credit limit is in use (0 while the balance is not negative). */
    public function getCreditUsed(): int
    {
        return max(0, -$this->balance);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isFrozen(): bool
    {
        return WalletStatus::FROZEN === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /**
     * @internal WalletService only, under the wallet row lock and together with the ledger row it caches
     */
    public function applyMovement(int $signedAmount, \DateTimeImmutable $at): void
    {
        $this->balance += $signedAmount;
        $this->lastActivityAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * @internal WalletService only
     */
    public function changeCreditLimit(int $creditLimit): void
    {
        $this->creditLimit = $creditLimit;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @internal WalletService only
     */
    public function changeStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
