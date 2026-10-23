<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Bot\Application\PaymentReceiptAdminNotifier;
use App\Entity\Payment;
use App\Entity\User;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Domain\OrderStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A card-to-card receipt: the customer sends a photo (stored as its Telegram file id, as the bot always did) or a
 * tracking code; the payment becomes SUBMITTED and the admins who moderate payments are alerted. Only an admin's
 * approval confirms it.
 *
 * Refused while a wallet payment of the order holds its debit (PaymentStarter::heldWalletPayment(): pending, in review
 * or waiting for a retry): the order is being paid, or was paid, from the wallet, and a card transfer on top would
 * make the customer pay twice. The submission re-checks that under the order's row lock (the lock WalletPayment::open()
 * takes), so a wallet payment opened at the same moment cannot slip in between. A receipt never moves an order out of
 * provisioning_failed (a paid order waiting for an admin retry).
 */
final class ReceiptSubmission
{
    public const READY = 'ready';
    public const NOT_YOURS = 'not_yours';
    public const ALREADY_SUBMITTED = 'already_submitted';
    public const NOT_PENDING = 'not_pending';
    public const NOT_MANUAL = 'not_manual';
    public const WALLET_PAYMENT_HELD = 'wallet_payment_held';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentReceiptAdminNotifier $adminNotifier,
        private readonly PaymentStarter $paymentStarter,
        private readonly TransactionRollback $transactionRollback,
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
        if (null !== $this->paymentStarter->heldWalletPayment($payment->getOrder())) {
            return self::WALLET_PAYMENT_HELD;
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
        self::keepWaiting($payment);
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

    /**
     * @return string READY when the receipt was recorded, else why not (WALLET_PAYMENT_HELD, NOT_PENDING): nothing changed
     */
    public function submitPhoto(Payment $payment, string $telegramFileId): string
    {
        return $this->submit($payment, static function (Payment $payment) use ($telegramFileId): void {
            $payment->setReceiptFileId($telegramFileId);
        }, PaymentReceiptAdminNotifier::KIND_RECEIPT_PHOTO);
    }

    /**
     * @return string READY when the receipt was recorded, else why not (WALLET_PAYMENT_HELD, NOT_PENDING): nothing changed
     */
    public function submitTrackingCode(Payment $payment, string $text): string
    {
        return $this->submit($payment, static function (Payment $payment) use ($text): void {
            $payment->setTrackingCode($text)->setReceiptMessage($text);
        }, PaymentReceiptAdminNotifier::KIND_TRACKING_CODE);
    }

    /**
     * Records the receipt under the order's and the payment's row locks, after checking again that no wallet payment of
     * the order holds its debit and that the payment still waits for a receipt (pending, or submitted: a new receipt
     * replaces the earlier one).
     *
     * @param \Closure(Payment): void $apply
     */
    private function submit(Payment $payment, \Closure $apply, string $kind): string
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($payment->getOrder(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $refusal = match (true) {
                !in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED], true) => self::NOT_PENDING,
                null !== $this->paymentStarter->heldWalletPayment($payment->getOrder()) => self::WALLET_PAYMENT_HELD,
                default => null,
            };
            if (null !== $refusal) {
                $connection->commit();

                return $refusal;
            }

            $apply($payment);
            $payment
                ->setStatus(PaymentStatus::SUBMITTED)
                ->setSubmittedAt(new \DateTimeImmutable());
            self::keepWaiting($payment);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();
            }

            throw $e;
        }
        $this->adminNotifier->notify($payment, $kind);

        return self::READY;
    }

    /** The order waits for this payment again, unless it is paid and waits for an admin retry (provisioning_failed). */
    private static function keepWaiting(Payment $payment): void
    {
        $order = $payment->getOrder();
        if (OrderStatus::PROVISIONING_FAILED !== $order->getStatus()) {
            $order->setStatus(OrderStatus::WAITING_PAYMENT);
        }
    }
}
