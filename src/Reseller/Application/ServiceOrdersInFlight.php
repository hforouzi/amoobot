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
 * Two ways to ask:
 *
 * - exists(): a plain read, for what a screen shows (may a link be made, is a button shown). Nothing is locked.
 * - existsLocked(): for an ownership change (claim, return to reseller), inside ClaimTransactions, AFTER the claim
 *   row lock and BEFORE the vpn_service row lock. The order rows of the service and their payments are read with a
 *   SHARED lock (LOCK IN SHARE MODE): it waits for a settlement step that holds them (settlement locks order, then
 *   payment, and writes the service row last), so it sees every step committed before; and until the ownership
 *   change commits, no settlement can move those orders on. Shared, not FOR UPDATE: it only reads, so two such checks
 *   never wait for each other and a plain reader is never blocked. Taking it before the service row keeps the order
 *   "claim row, order/payment rows, service row", which has no cycle with settlement ("order, payment, ..., service").
 *   Every lookup is by an index (order.target_service_id, order.id, payment.order_id): no table scan, no lock on other
 *   services' orders.
 */
class ServiceOrdersInFlight
{
    private const BUSY_ORDER = [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::PAYMENT_PENDING, OrderStatus::PROVISIONING_FAILED];
    private const WAITING_ORDER = [OrderStatus::WAITING_PAYMENT, OrderStatus::PENDING];
    private const PAYMENT_WITH_SOMEONE = [PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED];

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
                'busy' => self::BUSY_ORDER,
                'waiting' => self::WAITING_ORDER,
                'withSomeone' => self::PAYMENT_WITH_SOMEONE,
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

    /**
     * As exists(), with the order and payment rows read under a shared lock that lasts until the caller's transaction
     * ends (see the class comment for where it must be called).
     */
    public function existsLocked(int $serviceId): bool
    {
        $connection = $this->entityManager->getConnection();
        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException('existsLocked() needs the caller\'s transaction.');
        }
        // The service's own order never changes: a plain read (the service row itself is locked later, by the caller).
        $ownOrderId = $connection->fetchOne('SELECT order_id FROM vpn_service WHERE id = ?', [$serviceId]);
        $statuses = [];
        foreach ($connection->fetchAllAssociative('SELECT id, status FROM `order` WHERE target_service_id = ? LOCK IN SHARE MODE', [$serviceId]) as $row) {
            $statuses[(int) $row['id']] = (string) $row['status'];
        }
        if (null !== $ownOrderId && false !== $ownOrderId && !isset($statuses[(int) $ownOrderId])) {
            $status = $connection->fetchOne('SELECT status FROM `order` WHERE id = ? LOCK IN SHARE MODE', [(int) $ownOrderId]);
            if (false !== $status) {
                $statuses[(int) $ownOrderId] = (string) $status;
            }
        }
        $waiting = [];
        foreach ($statuses as $orderId => $status) {
            if (in_array($status, self::BUSY_ORDER, true)) {
                return true;
            }
            if (in_array($status, self::WAITING_ORDER, true)) {
                $waiting[] = $orderId;
            }
        }
        if ([] === $waiting) {
            return false;
        }
        $payments = $connection->fetchAllAssociative(
            'SELECT status, method FROM payment WHERE order_id IN (?) LOCK IN SHARE MODE',
            [$waiting],
            [ArrayParameterType::INTEGER],
        );
        foreach ($payments as $payment) {
            if (in_array($payment['status'], self::PAYMENT_WITH_SOMEONE, true) || (Payment::METHOD_WALLET === $payment['method'] && PaymentStatus::PENDING === $payment['status'])) {
                return true;
            }
        }

        return false;
    }
}
