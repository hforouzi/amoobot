<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether an order of a service is in settlement (docs/design/reseller-and-claim.md, 3.1 / 3.4): its own order or a
 * renewal / add-traffic order targeting it is being paid or provisioned, or is waiting for an admin after it was paid.
 * A claim link is neither made nor used while one is: the money and the service must not change hands mid-way.
 *
 * Counts as in settlement: an order paid / processing / provisioning_failed, or an order still waiting for payment
 * whose payment is with someone (a receipt submitted, needs review, provisioning failed) or whose wallet payment holds
 * its debit (a wallet purchase between open() and pay()).
 *
 * A plain (non-locking) read: the claim holds the claim and service row locks and never locks an order, payment or
 * wallet row, so it cannot deadlock with settlement (order -> payment -> user -> wallet). Read after those locks, it
 * sees every settlement step committed before.
 */
class ServiceOrdersInFlight
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function exists(int $serviceId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT o.id FROM `order` o
             WHERE (o.target_service_id = :service OR o.id = (SELECT s.order_id FROM vpn_service s WHERE s.id = :service))
               AND (o.status IN (:busy)
                    OR (o.status IN (:waiting) AND EXISTS (
                        SELECT 1 FROM payment p WHERE p.order_id = o.id
                          AND (p.status IN (:withSomeone) OR (p.method = :wallet AND p.status = :pending)))))
             LIMIT 1',
            [
                'service' => $serviceId,
                'busy' => [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::PAYMENT_PENDING, OrderStatus::PROVISIONING_FAILED],
                'waiting' => [OrderStatus::WAITING_PAYMENT, OrderStatus::PENDING],
                'withSomeone' => [PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED],
                'wallet' => Payment::METHOD_WALLET,
                'pending' => PaymentStatus::PENDING,
            ],
            [
                'busy' => ArrayParameterType::STRING,
                'waiting' => ArrayParameterType::STRING,
                'withSomeone' => ArrayParameterType::STRING,
            ],
        );
    }
}
