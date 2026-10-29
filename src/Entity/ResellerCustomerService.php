<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Which of a reseller's own customer labels a service he sold is assigned to: at most one label per service (unique
 * service_id). Removed with its label and with its service (ON DELETE CASCADE: a label never blocks deleting a service);
 * never removes or changes the service. Written only through
 * ResellerCustomers (both sides checked to be his).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reseller_customer_service')]
#[ORM\UniqueConstraint(name: 'uniq_reseller_customer_service_service', columns: ['service_id'])]
class ResellerCustomerService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResellerCustomer $customer;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VpnService $service;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    public function __construct(ResellerCustomer $customer, VpnService $service, \DateTimeImmutable $now)
    {
        $this->customer = $customer;
        $this->service = $service;
        $this->assignedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ResellerCustomer
    {
        return $this->customer;
    }

    public function getService(): VpnService
    {
        return $this->service;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function moveTo(ResellerCustomer $customer, \DateTimeImmutable $now): void
    {
        $this->customer = $customer;
        $this->assignedAt = $now;
    }
}
