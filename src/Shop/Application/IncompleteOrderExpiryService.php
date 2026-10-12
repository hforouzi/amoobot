<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class IncompleteOrderExpiryService
{
    private const EXPIRABLE_ORDER_STATUSES = [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT];

    /** Money has arrived (or an admin must decide): such an order is never expired. */
    private const PROTECTED_PAYMENT_STATUSES = [PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED, PaymentStatus::CONFIRMED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
    ) {
    }

    /**
     * @return array{draftsExpired:int,ordersExpired:int,paymentsRejected:int,ordersSkippedSubmittedReceipt:int,ordersSkippedPaymentInProgress:int}
     */
    public function expire(bool $dryRun = false, ?int $hours = null, int $limit = 100): array
    {
        $hours = $hours ?? $this->configuredHours();
        $hours = max(1, $hours);
        $limit = max(1, $limit);
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours));

        $result = [
            'draftsExpired' => 0,
            'ordersExpired' => 0,
            'paymentsRejected' => 0,
            'ordersSkippedSubmittedReceipt' => 0,
            'ordersSkippedPaymentInProgress' => 0,
        ];

        $drafts = $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.status IN (:statuses)')
            ->andWhere('COALESCE(d.updatedAt, d.createdAt) <= :cutoff')
            ->setParameter('statuses', [
                OrderDraftStatus::PENDING,
                OrderDraftStatus::AWAITING_USERNAME,
                OrderDraftStatus::AWAITING_TRAFFIC,
                OrderDraftStatus::AWAITING_DURATION,
                OrderDraftStatus::AWAITING_DISCOUNT_CHOICE,
                OrderDraftStatus::AWAITING_DISCOUNT_CODE,
                OrderDraftStatus::AWAITING_PAYMENT_METHOD,
            ])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        foreach ($drafts as $draft) {
            if (!$draft instanceof OrderDraft) {
                continue;
            }

            ++$result['draftsExpired'];
            if (!$dryRun) {
                $draft
                    ->setStatus(OrderDraftStatus::EXPIRED)
                    ->setUpdatedAt(new \DateTimeImmutable());
            }
        }

        $orderIds = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.createdAt <= :cutoff')
            ->setParameter('statuses', self::EXPIRABLE_ORDER_STATUSES)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        foreach ($orderIds as $orderId) {
            $this->expireOrder((int) $orderId, $dryRun, $result);
        }

        return $result;
    }

    /**
     * Expires one order in its own transaction. The order row is locked first and then its payments,
     * the same order PaymentSettlementService uses, so an order that is being settled right now is
     * only looked at after that settlement committed (and is then skipped because of its new state).
     *
     * @param array{draftsExpired:int,ordersExpired:int,paymentsRejected:int,ordersSkippedSubmittedReceipt:int,ordersSkippedPaymentInProgress:int} $result
     */
    private function expireOrder(int $orderId, bool $dryRun, array &$result): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $order = $this->entityManager->find(Order::class, $orderId);
            if (!$order instanceof Order) {
                $connection->commit();

                return;
            }
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (!in_array($order->getStatus(), self::EXPIRABLE_ORDER_STATUSES, true)) {
                $connection->commit();

                return;
            }

            $payments = [];
            foreach ($this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'DESC']) as $payment) {
                if ($payment instanceof Payment) {
                    $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
                    $payments[] = $payment;
                }
            }

            $statuses = array_map(static fn (Payment $payment): string => $payment->getStatus(), $payments);
            if (in_array(PaymentStatus::SUBMITTED, $statuses, true)) {
                ++$result['ordersSkippedSubmittedReceipt'];
                $connection->commit();

                return;
            }
            if ([] !== array_intersect($statuses, self::PROTECTED_PAYMENT_STATUSES)) {
                ++$result['ordersSkippedPaymentInProgress'];
                $connection->commit();

                return;
            }

            ++$result['ordersExpired'];
            if ($dryRun) {
                $connection->commit();

                return;
            }

            $order->setStatus(OrderStatus::EXPIRED);
            foreach ($payments as $payment) {
                if (PaymentStatus::PENDING === $payment->getStatus()) {
                    $payment
                        ->setStatus(PaymentStatus::REJECTED)
                        ->setAdminNote('Auto expired incomplete order');
                    ++$result['paymentsRejected'];
                }
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    public function configuredHours(): int
    {
        return $this->incompleteOrderSettingsProvider->expireHours();
    }
}
