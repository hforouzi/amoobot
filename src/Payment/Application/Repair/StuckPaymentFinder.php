<?php

declare(strict_types=1);

namespace App\Payment\Application\Repair;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds CONFIRMED payments whose order was never fulfilled (left behind by the pre-settlement code,
 * which could confirm a payment and then fail to provision). PaymentSettlementService never
 * produces these: it confirms only together with a successful fulfilment.
 */
class StuckPaymentFinder
{
    private const FULFILLED_ORDER_STATUSES = [OrderStatus::PROVISIONED, OrderStatus::COMPLETED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RenewalPanelComparator $panelComparator,
    ) {
    }

    /**
     * @return list<StuckPayment>
     */
    public function find(\DateTimeImmutable $confirmedBefore, int $limit): array
    {
        $payments = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p')
            ->join('p.order', 'o')
            ->where('p.status = :confirmed')
            ->andWhere('COALESCE(p.confirmedAt, p.createdAt) <= :before')
            ->setParameter('confirmed', PaymentStatus::CONFIRMED)
            ->setParameter('before', $confirmedBefore)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->toIterable();

        $stuck = [];
        foreach ($payments as $payment) {
            $found = $this->classify($payment);
            if ($found instanceof StuckPayment) {
                $stuck[] = $found;
                if (count($stuck) >= $limit) {
                    break;
                }
            }
        }

        return $stuck;
    }

    public function classify(Payment $payment): ?StuckPayment
    {
        if (PaymentStatus::CONFIRMED !== $payment->getStatus()) {
            return null;
        }

        $order = $payment->getOrder();
        // A top-up provisions nothing, so it is never "stuck" here: its credit is written in the confirming transaction.
        // A confirmed top-up without that credit is reported by app:wallet:reconcile (WalletReconciler::TOPUP_NOT_CREDITED).
        if (OrderType::WALLET_TOPUP === $order->getType()) {
            return null;
        }
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $this->classifyRenewal($payment, $order);
        }

        if ($this->hasServiceRow($order)) {
            return null;
        }

        if ($this->isOrderFulfilled($order)) {
            return new StuckPayment($payment, StuckPaymentCategory::NEW_SERVICE_SERVICE_MISSING, sprintf(
                'Order #%d is %s but has no service row (failed write, or the service was deleted).',
                $order->getId() ?? 0,
                $order->getStatus()
            ));
        }

        return new StuckPayment($payment, StuckPaymentCategory::NEW_SERVICE_NOT_PROVISIONED, sprintf(
            'Payment confirmed but order #%d (%s) was never provisioned.',
            $order->getId() ?? 0,
            $order->getStatus()
        ));
    }

    /**
     * Whether the order still looks unfulfilled. Re-checked under lock right before a payment is
     * moved, so a concurrent fix is not undone.
     */
    public function isStillUnfulfilled(Order $order, StuckPaymentCategory $category): bool
    {
        return match ($category) {
            StuckPaymentCategory::NEW_SERVICE_NOT_PROVISIONED => !$this->hasServiceRow($order) && !$this->isOrderFulfilled($order),
            StuckPaymentCategory::NEW_SERVICE_SERVICE_MISSING => !$this->hasServiceRow($order),
            StuckPaymentCategory::RENEWAL_NOT_APPLIED => !$this->isOrderFulfilled($order),
            default => false,
        };
    }

    private function classifyRenewal(Payment $payment, Order $order): ?StuckPayment
    {
        if ($this->isOrderFulfilled($order)) {
            return null;
        }

        $label = OrderType::RENEWAL === $order->getType() ? 'Renewal' : 'Add-traffic';
        $target = $order->getTargetService();
        if (!$target instanceof VpnService) {
            return new StuckPayment($payment, StuckPaymentCategory::RENEWAL_UNVERIFIABLE, sprintf('%s order #%d has no target service.', $label, $order->getId() ?? 0));
        }

        $comparison = $this->panelComparator->compare($target, OrderType::RENEWAL === $order->getType());
        $category = match ($comparison->state) {
            PanelStateComparison::UNCHANGED => StuckPaymentCategory::RENEWAL_NOT_APPLIED,
            PanelStateComparison::AHEAD => StuckPaymentCategory::RENEWAL_MAYBE_APPLIED_ON_PANEL,
            default => StuckPaymentCategory::RENEWAL_UNVERIFIABLE,
        };

        return new StuckPayment($payment, $category, sprintf(
            '%s order #%d not applied in the database (checked %s): %s',
            $label,
            $order->getId() ?? 0,
            (new \DateTimeImmutable())->format(\DATE_ATOM),
            $comparison->detail
        ));
    }

    private function hasServiceRow(Order $order): bool
    {
        return null !== $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);
    }

    private function isOrderFulfilled(Order $order): bool
    {
        return in_array($order->getStatus(), self::FULFILLED_ORDER_STATUSES, true) || null !== $order->getProvisionedAt();
    }
}
