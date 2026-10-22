<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\IncompleteOrderContext;
use App\Shop\Application\IncompleteOrderService;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An unpaid order's life: whether it can still be paid (resumed) or cancelled, its latest payment, the customer's
 * incomplete order (IncompleteOrderService), and cancelling it (PaymentSettlementService::cancelByCustomer, which
 * locks and re-checks, so a paid order is never cancelled).
 */
final class OrderLifecycle
{
    public const CANCEL_CANCELLED = 'cancelled';
    public const CANCEL_ALREADY_CLOSED = 'already_closed';
    public const CANCEL_NOT_ALLOWED = 'not_allowed';

    private const OPEN_STATUSES = [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentSettlementService $paymentSettlementService,
        private readonly IncompleteOrderService $incompleteOrderService,
    ) {
    }

    /** A new service / renewal / add-traffic order that is still waiting to be paid. */
    public function canEditForPayment(Order $order): bool
    {
        return in_array($order->getType(), [OrderType::NEW_SERVICE, OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)
            && in_array($order->getStatus(), self::OPEN_STATUSES, true);
    }

    public function canCancel(Order $order, ?Payment $payment): bool
    {
        if (in_array($order->getStatus(), [OrderStatus::PAID, OrderStatus::PROVISIONED, OrderStatus::PROCESSING, OrderStatus::COMPLETED], true)) {
            return false;
        }

        if ($payment instanceof Payment && in_array($payment->getStatus(), [PaymentStatus::CONFIRMED, PaymentStatus::SUBMITTED], true)) {
            return false;
        }

        return in_array($order->getStatus(), self::OPEN_STATUSES, true);
    }

    public function latestPayment(Order $order): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->where('p.order = :order')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The customer's order of this id while it can still be paid, else null. */
    public function editableOrder(User $user, int $orderId): ?Order
    {
        if ($orderId <= 0) {
            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order || $order->getUser()->getId() !== $user->getId() || !$this->canEditForPayment($order)) {
            return null;
        }

        return $order;
    }

    /** The customer's own order of this id in any status, else null. */
    public function ownedOrder(User $user, int $orderId): ?Order
    {
        $order = $orderId > 0 ? $this->entityManager->getRepository(Order::class)->find($orderId) : null;

        return $order instanceof Order && $order->getUser()->getId() === $user->getId() ? $order : null;
    }

    /** The customer's incomplete order (not a draft), if any. */
    public function incompleteOrder(User $user): ?Order
    {
        $context = $this->incompleteOrderService->findActiveIncompleteForUser($user);
        if ($context instanceof IncompleteOrderContext && 'order' === $context->type) {
            $order = $this->entityManager->getRepository(Order::class)->find($context->id);
            if ($order instanceof Order && $order->getUser()->getId() === $user->getId()) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return string CANCEL_*
     */
    public function cancel(Order $order): string
    {
        if (in_array($order->getStatus(), [OrderStatus::CANCELLED, OrderStatus::EXPIRED, OrderStatus::FAILED], true)) {
            return self::CANCEL_ALREADY_CLOSED;
        }
        if (!$this->canCancel($order, $this->latestPayment($order))) {
            return self::CANCEL_NOT_ALLOWED;
        }

        return SettlementOutcome::REJECTED === $this->paymentSettlementService->cancelByCustomer($order)->outcome
            ? self::CANCEL_CANCELLED
            : self::CANCEL_NOT_ALLOWED;
    }
}
