<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Bot\Application\PaymentReceiptAdminNotifier;
use App\Entity\Payment;
use App\Entity\User;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A card-to-card receipt: the customer sends a photo (stored as its Telegram file id, as the bot always did) or a
 * tracking code; the payment becomes SUBMITTED and the admins who moderate payments are alerted. Only an admin's
 * approval confirms it.
 */
final class ReceiptSubmission
{
    public const READY = 'ready';
    public const NOT_YOURS = 'not_yours';
    public const ALREADY_SUBMITTED = 'already_submitted';
    public const NOT_PENDING = 'not_pending';
    public const NOT_MANUAL = 'not_manual';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentReceiptAdminNotifier $adminNotifier,
    ) {
    }

    /**
     * Whether this customer may send a receipt for this payment now (READY or why not).
     */
    public function check(Payment $payment, User $user): string
    {
        if ($payment->getOrder()->getUser()->getId() !== $user->getId()) {
            return self::NOT_YOURS;
        }
        if (PaymentStatus::SUBMITTED === $payment->getStatus()) {
            return self::ALREADY_SUBMITTED;
        }
        if (PaymentStatus::PENDING !== $payment->getStatus()) {
            return self::NOT_PENDING;
        }

        return self::READY;
    }

    /** Whether the payment is card-to-card (receipts are only for those). */
    public static function isManual(Payment $payment): bool
    {
        return PaymentGatewayType::MANUAL_CARD === (string) ($payment->getGatewayType() ?? $payment->getMethod());
    }

    /** The bot's "send your receipt" step: the order is (still) waiting for its payment. */
    public function expectReceipt(Payment $payment): void
    {
        $payment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();
    }

    /** The customer's latest card-to-card payment a receipt would be for (pending or submitted). */
    public function openManualPayment(User $user): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->join('p.order', 'o')
            ->where('o.user = :user')
            ->andWhere('o.status = :orderStatus')
            ->andWhere('p.status IN (:paymentStatuses)')
            ->andWhere('(p.gatewayType = :manualGateway OR (p.gatewayType IS NULL AND p.method = :manualMethod))')
            ->setParameter('user', $user)
            ->setParameter('orderStatus', OrderStatus::WAITING_PAYMENT)
            ->setParameter('paymentStatuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->setParameter('manualGateway', PaymentGatewayType::MANUAL_CARD)
            ->setParameter('manualMethod', PaymentGatewayType::MANUAL_CARD)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function submitPhoto(Payment $payment, string $telegramFileId): void
    {
        $payment
            ->setReceiptFileId($telegramFileId)
            ->setStatus(PaymentStatus::SUBMITTED)
            ->setSubmittedAt(new \DateTimeImmutable());
        $payment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();
        $this->adminNotifier->notify($payment, PaymentReceiptAdminNotifier::KIND_RECEIPT_PHOTO);
    }

    public function submitTrackingCode(Payment $payment, string $text): void
    {
        $payment
            ->setTrackingCode($text)
            ->setReceiptMessage($text)
            ->setStatus(PaymentStatus::SUBMITTED)
            ->setSubmittedAt(new \DateTimeImmutable());
        $payment->getOrder()->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->entityManager->flush();
        $this->adminNotifier->notify($payment, PaymentReceiptAdminNotifier::KIND_TRACKING_CODE);
    }
}
