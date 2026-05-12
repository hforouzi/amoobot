<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\User;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class IncompleteOrderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findActiveIncompleteForUser(User $user): ?IncompleteOrderContext
    {
        $draft = $this->findLatestActiveDraft($user);
        $order = $this->findLatestActiveOrder($user);

        if (!$draft instanceof OrderDraft && !$order instanceof Order) {
            return null;
        }

        if ($draft instanceof OrderDraft && !$order instanceof Order) {
            return new IncompleteOrderContext('draft', (int) $draft->getId());
        }

        if ($order instanceof Order && !$draft instanceof OrderDraft) {
            return new IncompleteOrderContext('order', (int) $order->getId());
        }

        $draftUpdatedAt = $draft?->getUpdatedAt() ?? $draft?->getCreatedAt();
        $orderCreatedAt = $order?->getCreatedAt();

        if ($draftUpdatedAt instanceof \DateTimeImmutable && $orderCreatedAt instanceof \DateTimeImmutable && $draftUpdatedAt >= $orderCreatedAt) {
            return new IncompleteOrderContext('draft', (int) $draft?->getId());
        }

        return new IncompleteOrderContext('order', (int) $order?->getId());
    }

    public function hasActiveIncompleteForUser(User $user): bool
    {
        return $this->findActiveIncompleteForUser($user) instanceof IncompleteOrderContext;
    }

    public function resume(User $user): ?IncompleteOrderContext
    {
        return $this->findActiveIncompleteForUser($user);
    }

    public function cancel(User $user): ?IncompleteOrderContext
    {
        $context = $this->findActiveIncompleteForUser($user);
        if (!$context instanceof IncompleteOrderContext) {
            return null;
        }

        return $this->cancelContext($user, $context);
    }

    public function cancelContext(User $user, IncompleteOrderContext $context): ?IncompleteOrderContext
    {
        if (!in_array($context->type, ['draft', 'order'], true)) {
            return null;
        }

        if ('draft' === $context->type) {
            $draft = $this->entityManager->getRepository(OrderDraft::class)->find($context->id);
            if ($draft instanceof OrderDraft && $draft->getUser()->getId() === $user->getId()) {
                if (!in_array($draft->getStatus(), [
                    OrderDraftStatus::PENDING,
                    OrderDraftStatus::AWAITING_USERNAME,
                    OrderDraftStatus::AWAITING_TRAFFIC,
                    OrderDraftStatus::AWAITING_DURATION,
                    OrderDraftStatus::AWAITING_DISCOUNT_CHOICE,
                    OrderDraftStatus::AWAITING_DISCOUNT_CODE,
                    OrderDraftStatus::AWAITING_PAYMENT_METHOD,
                ], true)) {
                    return null;
                }
                $draft
                    ->setStatus(OrderDraftStatus::CANCELLED)
                    ->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                return $context;
            }

            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)->find($context->id);
        if (!$order instanceof Order || $order->getUser()->getId() !== $user->getId()) {
            return null;
        }
        if (!in_array($order->getStatus(), [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING], true)) {
            return null;
        }

        $order->setStatus(OrderStatus::CANCELLED);
        $payments = $this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'DESC']);
        $latestPayment = $payments[0] ?? null;
        if ($latestPayment instanceof Payment && PaymentStatus::PENDING !== $latestPayment->getStatus()) {
            return null;
        }
        foreach ($payments as $payment) {
            if ($payment instanceof Payment && PaymentStatus::PENDING === $payment->getStatus()) {
                $payment->setStatus(PaymentStatus::REJECTED);
            }
        }
        $this->entityManager->flush();

        return $context;
    }

    private function findLatestActiveDraft(User $user): ?OrderDraft
    {
        $drafts = $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                OrderDraftStatus::PENDING,
                OrderDraftStatus::AWAITING_USERNAME,
                OrderDraftStatus::AWAITING_TRAFFIC,
                OrderDraftStatus::AWAITING_DURATION,
                OrderDraftStatus::AWAITING_DISCOUNT_CHOICE,
                OrderDraftStatus::AWAITING_DISCOUNT_CODE,
                OrderDraftStatus::AWAITING_PAYMENT_METHOD,
            ])
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        foreach ($drafts as $draft) {
            if (!$draft instanceof OrderDraft) {
                continue;
            }
            if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
                continue;
            }

            return $draft;
        }

        return null;
    }

    private function findLatestActiveOrder(User $user): ?Order
    {
        $orders = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING])
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            $payment = $this->entityManager->getRepository(Payment::class)
                ->createQueryBuilder('p')
                ->where('p.order = :order')
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(1)
                ->setParameter('order', $order)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$payment instanceof Payment) {
                return $order;
            }
            if (PaymentStatus::PENDING === $payment->getStatus()) {
                return $order;
            }
        }

        return null;
    }
}
