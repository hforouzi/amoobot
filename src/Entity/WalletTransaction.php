<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One ledger row: append-only. It has no setters; WalletLedgerGuard refuses any update or delete through Doctrine.
 * A mistake is corrected by a new row (REVERSAL of this one, or an ADJUSTMENT), never by editing.
 *
 * $amount is signed (credit > 0, debit < 0), integer Toman; $balanceAfter is the wallet's balance right after this
 * row. $idempotencyKey is unique: the same key can never be applied twice (a retry gets the existing row back).
 * $reversalOf is unique too, so a row can be reversed at most once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wallet_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_wallet_transaction_key', columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_wallet_transaction_reversal_of', columns: ['reversal_of_id'])]
#[ORM\Index(name: 'idx_wallet_transaction_wallet', columns: ['wallet_id', 'id'])]
#[ORM\Index(name: 'idx_wallet_transaction_reference', columns: ['reference_type', 'reference_id'])]
class WalletTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Wallet $wallet,
        #[ORM\Column(length: 16)]
        private string $type,
        #[ORM\Column(type: Types::BIGINT)]
        private int $amount,
        #[ORM\Column(type: Types::BIGINT)]
        private int $balanceAfter,
        #[ORM\Column(length: 128)]
        private string $idempotencyKey,
        #[ORM\Column(length: 16)]
        private string $createdBySource,
        #[ORM\Column(nullable: true)]
        private ?int $createdByAdminId = null,
        #[ORM\Column(length: 190, nullable: true)]
        private ?string $createdByLabel = null,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $referenceType = null,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $referenceId = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note = null,
        // Rows are only ever deleted together with their whole wallet (the customer was deleted).
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?WalletTransaction $reversalOf = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWallet(): Wallet
    {
        return $this->wallet;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function getBalanceAfter(): int
    {
        return $this->balanceAfter;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedBySource(): string
    {
        return $this->createdBySource;
    }

    public function getCreatedByAdminId(): ?int
    {
        return $this->createdByAdminId;
    }

    public function getCreatedByLabel(): ?string
    {
        return $this->createdByLabel;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getReversalOf(): ?WalletTransaction
    {
        return $this->reversalOf;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
