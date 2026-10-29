<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A reseller's own label for one of his customers (docs/design/reseller-and-claim.md, S3): only what he typed (a name,
 * an optional phone and a note), never anything of a Telegram account. He assigns his sold services to it
 * (ResellerCustomerService). Written only through ResellerCustomers, which checks the values and that both belong to
 * him; deleting a label unassigns its services (ON DELETE CASCADE there), the services are not touched.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reseller_customer')]
class ResellerCustomer
{
    public const NAME_MAX = 64;
    public const PHONE_MAX = 20;
    public const NOTE_MAX = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Reseller $reseller;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: self::PHONE_MAX, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Reseller $reseller, string $name, ?string $phone, ?string $note, \DateTimeImmutable $now)
    {
        $this->reseller = $reseller;
        $this->name = $name;
        $this->phone = $phone;
        $this->note = $note;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReseller(): Reseller
    {
        return $this->reseller;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function change(string $name, ?string $phone, ?string $note, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->phone = $phone;
        $this->note = $note;
        $this->updatedAt = $now;
    }
}
