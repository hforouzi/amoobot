<?php

declare(strict_types=1);

namespace App\Payment\Application\Settlement;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\AdminPaymentAlerter;
use App\Payment\Application\PaymentCustomerNotifier;
use App\Payment\Domain\Money\AmountMatch;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceRenewalService;
use App\Provisioning\Application\ServiceTrafficAddonService;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * The ONLY place that moves a Payment to CONFIRMED (and to NEEDS_REVIEW / PROVISIONING_FAILED / REJECTED).
 *
 * Gateways only answer "was it paid, how much, which currency" (PaymentVerificationResult).
 * Everything else happens here, under a transaction holding PESSIMISTIC_WRITE locks on the order
 * and the payment (always in that order, so concurrent settlements cannot deadlock):
 *
 *   1. idempotency: a payment that is not in an allowed state is returned as ALREADY_PROCESSED
 *   2. strict amount check for gateway verifications (AmountMatcher, with the gateway's underpayment
 *      tolerance) -> NEEDS_REVIEW on mismatch; the review reason records the shortfall
 *   3. an order that is already fulfilled (second payment for it) -> NEEDS_REVIEW
 *   4. fulfilment: new service / renewal / add-traffic
 *   5. success: Payment CONFIRMED, Order PROVISIONED, discount usage recorded, commit
 *      failure: rollback, then Payment + Order PROVISIONING_FAILED with the error (retryable)
 *   6. after commit: customer messages and admin alerts (never inside the transaction)
 */
class PaymentSettlementService
{
    public const FLAG_OVERPAID = 'overpaid';
    public const FLAG_UNDERPAID_TOLERATED = 'underpaid_tolerated';

    /** Payments an admin may approve (manual receipt or a parked review). */
    private const ADMIN_APPROVABLE = [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW];
    /** Order states a customer may still cancel (nothing paid, nothing provisioned). */
    private const CUSTOMER_CANCELLABLE_ORDER_STATUSES = [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT];
    private const REJECTABLE = [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly AmountMatcher $amountMatcher,
        private readonly UnderpaymentTolerancePolicy $tolerancePolicy,
        private readonly VpnProvisioningService $vpnProvisioningService,
        private readonly ServiceRenewalService $serviceRenewalService,
        private readonly ServiceTrafficAddonService $serviceTrafficAddonService,
        private readonly DiscountCodeService $discountCodeService,
        private readonly PaymentCustomerNotifier $customerNotifier,
        private readonly AdminPaymentAlerter $adminAlerter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Settles a payment from a gateway verification or an admin decision.
     */
    public function settle(Payment $payment, VerifiedPayment $verified, string $source): SettlementResult
    {
        $allowed = $verified->byAdmin ? self::ADMIN_APPROVABLE : PaymentStatus::SETTLEABLE;

        return $this->run($payment, $verified, $source, $allowed, !$verified->byAdmin);
    }

    /**
     * Admin approval (manual card receipt, or a NEEDS_REVIEW decision). The approval is the verification.
     */
    public function approveByAdmin(Payment $payment, ?int $adminId, ?string $adminTelegramId, string $source): SettlementResult
    {
        return $this->settle($payment, VerifiedPayment::byAdmin($adminId, $adminTelegramId), $source);
    }

    /**
     * Retries fulfilment of a PROVISIONING_FAILED payment without re-verifying it.
     */
    public function retryProvisioning(Payment $payment, ?int $adminId, ?string $adminTelegramId, string $source): SettlementResult
    {
        return $this->run($payment, VerifiedPayment::byAdmin($adminId, $adminTelegramId), $source, [PaymentStatus::PROVISIONING_FAILED], false);
    }

    /**
     * Customer cancels an unpaid order (bot "cancel" buttons, incomplete-order flow).
     *
     * Under the order lock and then each payment's lock (the same order settle() uses), it re-checks
     * that the order is still open and that no payment is confirmed, in review or failed provisioning;
     * only then are its pending/submitted payments rejected (confirmationSource customer_cancel) and
     * the order cancelled. A cancel racing with a settlement therefore either waits and sees the
     * confirmed state (ALREADY_PROCESSED) or happens first, in which case a later "paid" goes to
     * NEEDS_REVIEW ("paid after cancel"). It never overwrites CONFIRMED/PROVISIONED.
     */
    public function cancelByCustomer(Order $order, string $source = Payment::SOURCE_CUSTOMER_CANCEL): SettlementResult
    {
        $orderId = (int) $order->getId();
        $connection = $this->entityManager->getConnection();

        try {
            $this->entityManager->flush();
            $connection->beginTransaction();
            $order = $this->entityManager->find(Order::class, $orderId) ?? throw new \RuntimeException(sprintf('Order #%d not found.', $orderId));
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $payments = [];
            foreach ($this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'ASC']) as $payment) {
                $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
                $payments[] = $payment;
            }

            $blocking = array_filter($payments, static fn (Payment $p): bool => in_array($p->getStatus(), [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true));
            if (!in_array($order->getStatus(), self::CUSTOMER_CANCELLABLE_ORDER_STATUSES, true) || null !== $order->getProvisionedAt() || [] !== $blocking) {
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, sprintf('Order is %s and can no longer be cancelled.', $order->getStatus()));
            }

            foreach ($payments as $payment) {
                if (in_array($payment->getStatus(), PaymentStatus::SETTLEABLE, true)) {
                    $payment
                        ->setStatus(PaymentStatus::REJECTED)
                        ->setAdminNote(Payment::NOTE_CUSTOMER_CANCEL)
                        // A late "paid" for this payment goes to NEEDS_REVIEW (parkLatePayment).
                        ->setConfirmationSource($source);
                }
            }
            $order->setStatus(OrderStatus::CANCELLED);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();
            $this->logger->error('Customer cancel failed.', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return new SettlementResult(SettlementOutcome::ERROR, 'Order could not be cancelled; try again.');
        }

        return new SettlementResult(SettlementOutcome::REJECTED, 'Order cancelled by the customer.');
    }

    /**
     * Admin rejection. Also closes a duplicate payment for an order another payment already
     * fulfilled: the payment is rejected, the fulfilled order is left as it is. $refunded records that
     * the money was returned to the customer.
     */
    public function reject(Payment $payment, ?string $reason, ?int $adminId, ?string $adminTelegramId, string $source, bool $refunded = false): SettlementResult
    {
        $paymentId = (int) $payment->getId();
        $connection = $this->entityManager->getConnection();

        try {
            $connection->beginTransaction();
            [$payment, $order] = $this->lock($paymentId);

            if (!in_array($payment->getStatus(), self::REJECTABLE, true)) {
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, 'Payment has already been processed.', $payment);
            }

            $payment
                ->setStatus(PaymentStatus::REJECTED)
                ->setAdminNote($reason)
                ->setConfirmationSource($source)
                ->setConfirmedBy($adminId, $adminTelegramId)
                ->setRefundedAt($refunded ? new \DateTimeImmutable() : null);
            // A duplicate payment of a fulfilled order: close the payment, never touch the order.
            if (!$this->isOrderFulfilled($order)) {
                $order->setStatus(OrderStatus::FAILED);
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();
            $this->logger->error('Payment rejection failed.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return new SettlementResult(SettlementOutcome::ERROR, 'Payment could not be rejected; try again.');
        }

        $this->customerNotifier->rejected($payment);

        return new SettlementResult(SettlementOutcome::REJECTED, $refunded ? 'Payment rejected and recorded as refunded.' : 'Payment rejected.', $payment);
    }

    /**
     * Repair of a CONFIRMED payment whose order was never fulfilled (left by the pre-settlement code):
     * moves payment and order to PROVISIONING_FAILED so an admin can retry fulfilment.
     *
     * $stillUnfulfilled is evaluated under the order/payment locks; if it returns false (someone fixed
     * the order in the meantime) nothing is changed. No customer message is sent.
     *
     * @param \Closure(Order): bool $stillUnfulfilled
     */
    public function markStuckForRetry(Payment $payment, \Closure $stillUnfulfilled, string $reason, string $source): SettlementResult
    {
        $paymentId = (int) $payment->getId();
        $connection = $this->entityManager->getConnection();

        try {
            $connection->beginTransaction();
            [$payment, $order] = $this->lock($paymentId);
            if (PaymentStatus::CONFIRMED !== $payment->getStatus() || !$stillUnfulfilled($order)) {
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, sprintf('Payment is %s and no longer stuck.', $payment->getStatus()), $payment);
            }

            $now = new \DateTimeImmutable();
            $payment
                ->setStatus(PaymentStatus::PROVISIONING_FAILED)
                ->setProvisioningError(mb_substr(sprintf('[%s] %s', $source, $reason), 0, 2000))
                ->setProvisioningFailedAt($now);
            $order->setStatus(OrderStatus::PROVISIONING_FAILED);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();
            $this->logger->error('Marking stuck payment failed.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return new SettlementResult(SettlementOutcome::ERROR, 'Payment could not be marked; nothing was changed.');
        }

        $this->logger->warning('Stuck confirmed payment moved to provisioning_failed.', ['payment_id' => $paymentId, 'source' => $source]);

        return new SettlementResult(SettlementOutcome::PROVISIONING_FAILED, 'Payment moved to provisioning_failed for an admin retry.', $payment);
    }

    /**
     * The provider itself (verifyPayment(), not the request) says the payment failed or expired.
     * Only an unsettled payment is closed; the order is left as is, so the customer can pay again.
     */
    public function recordGatewayFailure(Payment $payment, string $source): SettlementResult
    {
        $paymentId = (int) $payment->getId();
        $connection = $this->entityManager->getConnection();

        try {
            $connection->beginTransaction();
            [$payment] = $this->lock($paymentId);
            if (!in_array($payment->getStatus(), PaymentStatus::SETTLEABLE, true)) {
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, sprintf('Payment is already %s.', $payment->getStatus()), $payment);
            }

            $payment
                ->setStatus(PaymentStatus::REJECTED)
                ->setFailedAt($payment->getFailedAt() ?? new \DateTimeImmutable())
                ->setConfirmationSource($source);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();
            $this->logger->error('Recording gateway failure failed.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return new SettlementResult(SettlementOutcome::ERROR, 'Payment state could not be updated; try again.');
        }

        return new SettlementResult(SettlementOutcome::REJECTED, 'Gateway reported the payment as failed or expired.', $payment);
    }

    /**
     * @param list<string> $allowedStatuses
     */
    private function run(Payment $payment, VerifiedPayment $verified, string $source, array $allowedStatuses, bool $checkAmount): SettlementResult
    {
        $paymentId = (int) $payment->getId();
        $connection = $this->entityManager->getConnection();
        $fulfilmentStarted = false;

        try {
            $connection->beginTransaction();
            [$payment, $order] = $this->lock($paymentId);

            // The customer paid after the payment was cancelled or expired unpaid: never ignore money.
            if ($checkAmount && $payment->isRejectedUnpaid()) {
                return $this->parkLatePayment($payment, $order, $verified, $source);
            }

            if (!in_array($payment->getStatus(), $allowedStatuses, true)) {
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, sprintf('Payment is already %s.', $payment->getStatus()), $payment, $this->serviceFor($order));
            }

            $match = null;
            if ($checkAmount) {
                [$expectedAmount, $expectedCurrency] = AmountMatcher::expectationFor($payment);
                $match = $this->amountMatcher->match($expectedAmount, $expectedCurrency, $verified->amount, $verified->currency, $this->tolerancePolicy->percentFor($payment));
                $payment
                    ->setVerifiedMoney($verified->amount, $verified->currency)
                    ->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable());

                if (!$match->status->isAcceptable()) {
                    return $this->parkForReview($payment, $match->describe(), $source, $match);
                }
                $payment->setAmountFlag(match ($match->status) {
                    AmountMatchStatus::OVERPAID => self::FLAG_OVERPAID,
                    AmountMatchStatus::UNDERPAID_WITHIN_TOLERANCE => self::FLAG_UNDERPAID_TOLERATED,
                    default => null,
                });
            }

            $fulfilledBy = $this->fulfilledByPayment($order);
            if ($fulfilledBy instanceof Payment || OrderStatus::PROVISIONED === $order->getStatus()) {
                if ($verified->byAdmin) {
                    // Approve/Retry on a duplicate: fulfilling again would provision twice. Do not
                    // re-park it in review either; tell the admin what to do instead.
                    $connection->commit();

                    return new SettlementResult(SettlementOutcome::ORDER_ALREADY_FULFILLED, sprintf(
                        'Order #%d is already fulfilled%s; approving would provision it twice. Reject this payment instead (and mark it refunded once the money is returned).',
                        $order->getId() ?? 0,
                        $fulfilledBy instanceof Payment ? sprintf(' by payment #%d', $fulfilledBy->getId() ?? 0) : ''
                    ), $payment);
                }

                return $this->parkForReview($payment, sprintf(
                    'Order #%d is already fulfilled%s; this looks like a duplicate payment.',
                    $order->getId() ?? 0,
                    $fulfilledBy instanceof Payment ? sprintf(' by payment #%d', $fulfilledBy->getId() ?? 0) : ''
                ), $source, $match);
            }

            if (null !== $verified->transactionId && null === $payment->getGatewayTransactionId()) {
                $payment->setGatewayTransactionId($verified->transactionId);
            }

            $fulfilmentStarted = true;
            $service = $this->fulfil($order, $payment, $source);

            $now = new \DateTimeImmutable();
            $payment
                ->setStatus(PaymentStatus::CONFIRMED)
                ->setConfirmedAt($payment->getConfirmedAt() ?? $now)
                ->setConfirmationSource($source)
                ->setConfirmedBy($verified->adminId, $verified->adminTelegramId)
                ->setReviewReason(null)
                ->setProvisioningError(null)
                ->incrementProvisioningAttempts();
            $order
                ->setStatus(OrderStatus::PROVISIONED)
                ->setPaidAt($order->getPaidAt() ?? $now)
                ->setProvisionedAt($order->getProvisionedAt() ?? $now);
            $this->markOrderDiscountUsed($order);

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();

            if (!$fulfilmentStarted) {
                $this->logger->error('Payment settlement aborted before fulfilment.', ['payment_id' => $paymentId, 'source' => $source, 'error' => $e->getMessage()]);

                return new SettlementResult(SettlementOutcome::ERROR, 'Payment could not be settled right now; nothing was changed.');
            }

            return $this->recordProvisioningFailure($paymentId, $allowedStatuses, $verified, $source, $e);
        }

        $this->logger->info('Payment settled.', ['payment_id' => $paymentId, 'source' => $source, 'flag' => $payment->getAmountFlag()]);
        $this->customerNotifier->confirmed($payment, $service);
        if (self::FLAG_OVERPAID === $payment->getAmountFlag()) {
            $this->adminAlerter->overpaid($payment);
        }

        return new SettlementResult(SettlementOutcome::CONFIRMED, 'Payment confirmed and order fulfilled.', $payment, $service, $match);
    }

    /**
     * Locks order, then payment (fixed order => no deadlocks) and reloads both from the database.
     *
     * @return array{Payment, Order}
     */
    private function lock(int $paymentId): array
    {
        $payment = $this->entityManager->find(Payment::class, $paymentId);
        if (!$payment instanceof Payment) {
            throw new \RuntimeException(sprintf('Payment #%d not found.', $paymentId));
        }

        // Persist what verifyPayment() recorded on the entity (crypto status, ids, payloads) before the
        // refresh below re-reads the row. Only changed fields are written, never a stale status.
        $this->entityManager->flush();

        $order = $payment->getOrder();
        // refresh() with a lock mode issues SELECT ... FOR UPDATE and re-reads the row; a plain find()
        // of an already managed entity would lock but keep the stale in-memory status.
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);

        return [$payment, $order];
    }

    private function fulfil(Order $order, Payment $payment, string $source): VpnService
    {
        if (OrderType::RENEWAL === $order->getType() || OrderType::ADD_TRAFFIC === $order->getType()) {
            $target = $order->getTargetService();
            if (!$target instanceof VpnService) {
                throw new \RuntimeException(sprintf('Target service for %s order #%d not found.', $order->getType(), $order->getId() ?? 0));
            }
            $this->entityManager->refresh($target, LockMode::PESSIMISTIC_WRITE);

            if (OrderType::RENEWAL === $order->getType()) {
                $this->serviceRenewalService->renew($target, $order);

                return $target;
            }

            $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            $trafficGb = (int) ($metadata['trafficGb'] ?? 0);
            if ($trafficGb <= 0) {
                throw new \RuntimeException(sprintf('Add-traffic order #%d has no valid trafficGb.', $order->getId() ?? 0));
            }
            $this->serviceTrafficAddonService->addTraffic($target, $trafficGb, $order);

            return $target;
        }

        $plan = $order->getPlan();
        $inbound = $plan->getInbound();

        return $this->vpnProvisioningService->provisionOrder($order, [
            'source' => $source,
            'orderId' => $order->getId() ?? 0,
            'paymentId' => $payment->getId() ?? 0,
            'planId' => $plan->getId() ?? 0,
            'planInboundId' => $inbound?->getId() ?? 0,
            'panelId' => $inbound?->getPanel()?->getId() ?? 0,
            'driverType' => (string) ($inbound?->getPanel()?->getType() ?? 'dummy'),
        ],
            // Every attempt first looks for the order's client on the panel and adopts it: an earlier
            // attempt may have created it and then died before the commit (crash, timeout, kill), and
            // the next webhook/retry must not create a second one. Safe: the UUID is derived from the order.
            adoptExisting: true,
        );
    }

    private function parkLatePayment(Payment $payment, Order $order, VerifiedPayment $verified, string $source): SettlementResult
    {
        [$expectedAmount, $expectedCurrency] = AmountMatcher::expectationFor($payment);
        $match = $this->amountMatcher->match($expectedAmount, $expectedCurrency, $verified->amount, $verified->currency, $this->tolerancePolicy->percentFor($payment));
        $payment
            ->setVerifiedMoney($verified->amount, $verified->currency)
            ->setVerifiedAt($payment->getVerifiedAt() ?? new \DateTimeImmutable());
        if (null !== $verified->transactionId && null === $payment->getGatewayTransactionId()) {
            $payment->setGatewayTransactionId($verified->transactionId);
        }

        $cancelled = $payment->isCancelledByCustomer();

        return $this->parkForReview($payment, sprintf(
            '%s%s: order #%d was %s (%s) and the payment rejected unpaid; the gateway now reports it paid. %s',
            Payment::REVIEW_PREFIX_LATE_PAYMENT,
            $cancelled ? 'cancel' : 'expiry',
            $order->getId() ?? 0,
            $cancelled ? 'cancelled by the customer' : 'expired',
            $order->getStatus(),
            $match->describe()
        ), $source, $match);
    }

    private function parkForReview(Payment $payment, string $reason, string $source, ?AmountMatch $match): SettlementResult
    {
        $payment
            ->setStatus(PaymentStatus::NEEDS_REVIEW)
            ->setReviewReason($reason)
            ->setConfirmationSource($source);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->commit();

        $this->logger->warning('Payment parked for review.', ['payment_id' => $payment->getId(), 'source' => $source, 'reason' => $reason]);
        $this->adminAlerter->needsReview($payment);
        $this->customerNotifier->underReview($payment);

        return new SettlementResult(SettlementOutcome::NEEDS_REVIEW, $reason, $payment, null, $match);
    }

    /**
     * @param list<string> $allowedStatuses
     */
    private function recordProvisioningFailure(int $paymentId, array $allowedStatuses, VerifiedPayment $verified, string $source, \Throwable $error): SettlementResult
    {
        $reason = $this->sanitizeError($error);
        $this->logger->error('Payment fulfilment failed; rolled back.', ['payment_id' => $paymentId, 'source' => $source, 'error' => $reason]);

        $connection = $this->entityManager->getConnection();
        try {
            $connection->beginTransaction();
            [$payment, $order] = $this->lock($paymentId);
            if (!in_array($payment->getStatus(), $allowedStatuses, true)) {
                // Someone else settled it in the meantime.
                $connection->commit();

                return new SettlementResult(SettlementOutcome::ALREADY_PROCESSED, sprintf('Payment is already %s.', $payment->getStatus()), $payment, $this->serviceFor($order));
            }

            $now = new \DateTimeImmutable();
            if (!$verified->byAdmin) {
                $payment->setVerifiedMoney($verified->amount, $verified->currency);
            }
            $payment
                ->setStatus(PaymentStatus::PROVISIONING_FAILED)
                ->setVerifiedAt($payment->getVerifiedAt() ?? $now)
                ->setProvisioningError($reason)
                ->setProvisioningFailedAt($now)
                ->setConfirmationSource($source)
                ->setConfirmedBy($verified->adminId, $verified->adminTelegramId)
                ->incrementProvisioningAttempts();
            $order
                ->setStatus(OrderStatus::PROVISIONING_FAILED)
                ->setPaidAt($order->getPaidAt() ?? $now);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollbackAndReset();
            $this->logger->critical('Could not record provisioning failure.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return new SettlementResult(SettlementOutcome::ERROR, 'Fulfilment failed and the failure could not be recorded; nothing was changed.');
        }

        $this->adminAlerter->provisioningFailed($payment);
        $this->customerNotifier->provisioningDelayed($payment);

        return new SettlementResult(SettlementOutcome::PROVISIONING_FAILED, 'Payment verified, but the service could not be created: '.$reason, $payment);
    }

    /**
     * Another payment that already fulfilled this order (confirmed, or a service exists for it).
     */
    private function fulfilledByPayment(Order $order): ?Payment
    {
        $confirmed = $this->entityManager->getRepository(Payment::class)->findOneBy([
            'order' => $order,
            'status' => PaymentStatus::CONFIRMED,
        ]);
        if ($confirmed instanceof Payment) {
            return $confirmed;
        }

        return null;
    }

    private function isOrderFulfilled(Order $order): bool
    {
        return $this->fulfilledByPayment($order) instanceof Payment
            || in_array($order->getStatus(), [OrderStatus::PROVISIONED, OrderStatus::COMPLETED], true)
            || null !== $order->getProvisionedAt();
    }

    private function serviceFor(Order $order): ?VpnService
    {
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        return $service instanceof VpnService ? $service : null;
    }

    private function markOrderDiscountUsed(Order $order): void
    {
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];
        $discountCodeText = strtoupper(trim((string) ($priceSnapshot['discountCode'] ?? '')));
        if ('' === $discountCodeText) {
            return;
        }

        $discountCode = $this->entityManager->getRepository(DiscountCode::class)->findOneBy(['code' => $discountCodeText]);
        if (!$discountCode instanceof DiscountCode) {
            return;
        }

        $before = (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $order->getAmount());
        $discount = (int) ($priceSnapshot['discountCodeAmount'] ?? 0);
        $after = (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount());
        $this->discountCodeService->markUsed($discountCode, $order->getUser(), $order, $before, $discount, $after);
    }

    /**
     * After a failed attempt the unit of work holds half-applied changes (new service, statuses),
     * so it is discarded: cleared, or rebuilt when the failure closed the entity manager.
     */
    private function rollbackAndReset(): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Rollback failed.', ['error' => $e->getMessage()]);
        }

        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->managerRegistry->resetManager();
        }
    }

    private function sanitizeError(\Throwable $error): string
    {
        $message = sprintf('%s: %s', (new \ReflectionClass($error))->getShortName(), $error->getMessage());
        $message = (string) preg_replace('/(password|passwd|token|secret|api[_-]?key|authorization)(["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i', '$1$2[redacted]', $message);
        $message = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);
        $message = (string) preg_replace('#(https?://)[^/\s:@]+:[^@\s/]+@#i', '$1[redacted]@', $message);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 1000);
    }
}
