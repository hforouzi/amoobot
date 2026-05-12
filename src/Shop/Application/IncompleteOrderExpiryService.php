<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

final class IncompleteOrderExpiryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
    ) {
    }

    /**
     * @return array{draftsExpired:int,ordersExpired:int,paymentsRejected:int,ordersSkippedSubmittedReceipt:int}
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

        $orders = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.createdAt <= :cutoff')
            ->setParameter('statuses', [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $payments = $this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'DESC'], 20);
            $hasSubmitted = false;
            foreach ($payments as $payment) {
                if ($payment instanceof Payment && PaymentStatus::SUBMITTED === $payment->getStatus()) {
                    $hasSubmitted = true;
                    break;
                }
            }

            if ($hasSubmitted) {
                ++$result['ordersSkippedSubmittedReceipt'];
                continue;
            }

            ++$result['ordersExpired'];
            if ($dryRun) {
                continue;
            }

            $order->setStatus(OrderStatus::EXPIRED);
            foreach ($payments as $payment) {
                if (!$payment instanceof Payment) {
                    continue;
                }
                if (PaymentStatus::PENDING === $payment->getStatus()) {
                    $payment
                        ->setStatus(PaymentStatus::REJECTED)
                        ->setAdminNote('Auto expired incomplete order');
                    ++$result['paymentsRejected'];
                }
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $result;
    }

    public function configuredHours(): int
    {
        return $this->incompleteOrderSettingsProvider->expireHours();
    }
}
