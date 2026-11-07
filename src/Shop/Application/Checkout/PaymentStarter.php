<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Bot\Application\StorePaymentMethodResolver;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PaymentGatewayConstraintsInterface;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Domain\OrderType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paying an order: the store payment methods it may use (StorePaymentMethodResolver: active, configured, currency,
 * amount range) and starting a payment with one of them. The order's open payment (pending/submitted) is reused, so
 * choosing again never creates a second one; the gateway's createPayment() fills in its URL / authority. A debited
 * wallet payment is never reused, and while one holds its debit no gateway payment is started or reused
 * (KIND_WALLET_PAYMENT_PENDING, KIND_WALLET_PAYMENT_HELD). Nothing
 * here verifies or confirms a payment: that is PaymentSettlementService's, after the gateway says so.
 *
 * A gateway that declares an invoice lifetime (PaymentGatewayConstraintsInterface) keeps one invoice per row, and only
 * such a row has an expiresAt: see invoiceState(). createPayment() runs outside the lock, so its claim writes a short
 * reservation into expiresAt; the invoice replaces it. Every other gateway keeps reusing the open row.
 */
final class PaymentStarter
{
    /** The statuses in which a wallet payment holds its debit (heldWalletPayment()). */
    private const HELD_WALLET_STATUSES = [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED];
    /** How long a claim keeps the row for its createPayment(). After that nothing is running, or it crashed. */
    private const RESERVATION_SECONDS = 90;
    /** The answer while another start is creating this order's invoice. */
    public const INVOICE_IN_PROGRESS_MESSAGE = 'پرداخت این سفارش در حال آماده‌سازی است. لطفاً چند ثانیه دیگر دوباره تلاش کنید.';

    /** invoiceState(): start on the open row, or on a new row when there is none. */
    private const INVOICE_START = 'start';
    /** invoiceState(): another start is creating the row's invoice. */
    private const INVOICE_RESERVED = 'reserved';
    /** invoiceState(): the row's invoice can still be paid, so the gateway hands it out again. */
    private const INVOICE_LIVE = 'live';
    /** invoiceState(): the row's invoice is over or another gateway's, so a new row. */
    private const INVOICE_REPLACE = 'replace';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorePaymentMethodResolver $methodResolver,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly PlanSaleRule $saleRule,
        private readonly string $paymentCardNumber = '',
        private readonly string $paymentCardHolder = '',
        private readonly ?string $paymentDescription = null,
    ) {
    }

    /**
     * @return list<StorePaymentMethod>
     */
    public function methodsFor(Order $order): array
    {
        return $this->methodResolver->getAvailableMethods($order);
    }

    public function methodFor(Order $order, int $storePaymentMethodId): ?StorePaymentMethod
    {
        foreach ($this->methodsFor($order) as $method) {
            if ((int) ($method->getId() ?? 0) === $storePaymentMethodId) {
                return $method;
            }
        }

        return null;
    }

    public function methodForGateway(Order $order, int $gatewayId): ?StorePaymentMethod
    {
        foreach ($this->methodsFor($order) as $method) {
            if ((int) ($method->getGateway()->getId() ?? 0) === $gatewayId) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Why no method is offered (for the logs).
     *
     * @return array{
     *     orderId: int,
     *     amount: int,
     *     payableAmount: int,
     *     currency: string,
     *     activeStorePaymentMethodCount: int,
     *     skippedReasons: list<string>,
     *     methods: list<array<string, mixed>>
     * }
     */
    public function diagnostics(Order $order): array
    {
        return $this->methodResolver->getDiagnostics($order);
    }

    /**
     * Creates (or reuses) the order's payment with this method and asks the gateway to start it. The amount is the
     * order's own (for a new service its price snapshot's final amount), never one from the client.
     */
    public function start(Order $order, StorePaymentMethod $storePaymentMethod): PaymentStart
    {
        if ($order->isResellerOrder()) {
            // methodsFor() offers no method for it (StorePaymentMethodResolver); a caller that got one elsewhere is a bug.
            throw new \LogicException(sprintf('Reseller order #%d is paid from the wallet only.', (int) $order->getId()));
        }
        $gateway = $storePaymentMethod->getGateway();
        if (!$this->saleRule->acceptsPayment($order)) {
            return new PaymentStart(PaymentStart::KIND_PLAN_INACTIVE, null, false, null, 0);
        }

        // A gateway that cannot be loaded has no lifetime: the claim goes on as ever, then the failure surfaces as ever.
        $driver = $this->driver($gateway);
        $lifetime = $driver instanceof PaymentGatewayConstraintsInterface ? $driver->invoiceLifetimeSeconds() : null;

        $claimed = $this->claimPayment($order, $storePaymentMethod, $lifetime);
        if ($claimed instanceof PaymentStart) {
            return $claimed;
        }
        [$payment, $paymentWasCreated, $amount, $reservation] = $claimed;
        if ($driver instanceof \Throwable) {
            throw $driver;
        }

        try {
            $requestResult = $driver->createPayment($payment, $order);
        } catch (\Throwable $e) {
            if (null !== $reservation && $this->entityManager->isOpen()) {
                $this->endReservation($payment, $reservation, (int) $lifetime);
                $this->entityManager->flush();
            }

            throw $e;
        }

        if ($requestResult->rawResponse !== null) {
            $payment->setRequestPayload($requestResult->rawResponse);
        }
        if ($requestResult->transactionId) {
            $payment->setGatewayTransactionId($requestResult->transactionId);
        }
        if ($requestResult->authority) {
            $payment->setAuthority($requestResult->authority);
        }
        if ($requestResult->paymentUrl) {
            $payment->setPaymentUrl($requestResult->paymentUrl);
        }
        if (!$requestResult->success) {
            $payment->setAdminNote($requestResult->message);
            if (PaymentGatewayType::NOWPAYMENTS === $gateway->getType() && NowPaymentsGateway::BELOW_MINIMUM_USER_MESSAGE === $requestResult->message) {
                $this->rejectBelowMinimum($payment, $paymentWasCreated);
            }
        }
        if (null !== $reservation) {
            $this->endReservation($payment, $reservation, (int) $lifetime);
        }
        $this->entityManager->flush();

        return new PaymentStart(self::kindOf($gateway), $payment, $requestResult->success, $requestResult->message, $amount);
    }

    /** Card-to-card details: the gateway's own, else the shop settings, else the environment defaults. */
    public function manualCard(?PaymentGateway $gateway): ManualCardDetails
    {
        return new ManualCardDetails(
            cardNumber: $gateway?->getManualCardNumber() ?? $this->settingValueProvider->get('payment.card_number', $this->paymentCardNumber),
            cardHolder: $gateway?->getManualCardHolder() ?? $this->settingValueProvider->get('payment.card_holder', $this->paymentCardHolder),
            instructions: $gateway?->getManualInstructions() ?? $this->settingValueProvider->get('payment.description', $this->paymentDescription),
        );
    }

    public static function kindOf(PaymentGateway $gateway): string
    {
        return match (true) {
            in_array($gateway->getType(), [PaymentGatewayType::ZIBAL, PaymentGatewayType::CUSTOM_API], true) || null !== $gateway->getPluginCode() => PaymentStart::KIND_ONLINE,
            PaymentGatewayType::NOWPAYMENTS === $gateway->getType() => PaymentStart::KIND_CRYPTO,
            default => PaymentStart::KIND_MANUAL_CARD,
        };
    }

    /** What paying the order costs: its own amount, for a new service its price snapshot's final amount. */
    public function payableAmount(Order $order): int
    {
        if (OrderType::NEW_SERVICE !== $order->getType()) {
            return $order->getAmount();
        }
        $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $priceSnapshot = is_array($metadata['priceSnapshot'] ?? null) ? $metadata['priceSnapshot'] : [];

        return (int) ($priceSnapshot['finalAmount'] ?? $order->getAmount());
    }

    /**
     * Under the order's row lock (the lock WalletPayment::open() takes too): the payment to start, i.e. the order's open
     * gateway payment reused or a new one, flushed. While a wallet payment of the order holds its debit (pending, in
     * review, or provisioning_failed) nothing is started or reused: that payment is never taken over (its method would
     * change and its debit could no longer be given back) and no second payment is started beside it, which would make
     * the customer pay twice. Then the refusal is answered (KIND_WALLET_PAYMENT_PENDING / _HELD). The same while another
     * start is creating the invoice of the open row: a failed start of that row, for the customer to try again. KIND_ONLINE
     * whatever the chosen method is, so that no caller shows a chosen manual card's details for a refused start.
     *
     * @return array{Payment, bool, int, ?\DateTimeImmutable}|PaymentStart [payment, created, amount, reservation written], or the refusal
     */
    private function claimPayment(Order $order, StorePaymentMethod $storePaymentMethod, ?int $lifetime): array|PaymentStart
    {
        $gateway = $storePaymentMethod->getGateway();
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $connection->beginTransaction();
        $payment = null;
        $paymentWasCreated = false;

        try {
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $held = $this->heldWalletPayment($order);
            if ($held instanceof Payment) {
                $connection->commit();

                return new PaymentStart(PaymentStatus::PENDING === $held->getStatus() ? PaymentStart::KIND_WALLET_PAYMENT_PENDING : PaymentStart::KIND_WALLET_PAYMENT_HELD, null, false, null, 0);
            }

            $amount = $this->payableAmount($order);
            $payment = $this->lockedOpenPayment($order);
            $now = new \DateTimeImmutable();
            $state = $this->invoiceState($payment, $gateway, $lifetime, $amount, $now);
            if (self::INVOICE_RESERVED === $state && $payment instanceof Payment) {
                $connection->commit();

                return new PaymentStart(PaymentStart::KIND_ONLINE, $payment, false, self::INVOICE_IN_PROGRESS_MESSAGE, $amount);
            }
            if (self::INVOICE_REPLACE === $state && $payment instanceof Payment) {
                $this->supersede($payment);
                $payment = null;
            }

            if (!$payment instanceof Payment) {
                $payment = (new Payment())
                    ->setOrder($order)
                    ->setGateway($gateway)
                    ->setStorePaymentMethod($storePaymentMethod)
                    ->setGatewayType($gateway->getType())
                    ->setMethod($gateway->getType())
                    ->setCurrency($gateway->getCurrency())
                    ->setAmount($amount)
                    ->setPayableAmount($amount)
                    ->setStatus(PaymentStatus::PENDING);
                $this->entityManager->persist($payment);
                $paymentWasCreated = true;
            } else {
                $payment
                    ->setGateway($gateway)
                    ->setStorePaymentMethod($storePaymentMethod)
                    ->setGatewayType($gateway->getType())
                    ->setMethod($gateway->getType())
                    ->setCurrency($gateway->getCurrency());
            }
            $reservation = null === $lifetime || self::INVOICE_LIVE === $state ? null : $now->modify(sprintf('+%d seconds', self::RESERVATION_SECONDS));
            if (self::INVOICE_LIVE !== $state) {
                // Without a lifetime this also drops what a stale reservation left: the row is no invoice any more.
                $payment->setExpiresAt($reservation);
            }
            if (null !== $reservation) {
                // The invoice is created now, so at today's price, even on a reused row.
                $payment->setAmount($amount)->setPayableAmount($amount);
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($paymentWasCreated && $payment instanceof Payment && $this->entityManager->isOpen()) {
                // Rolled back: the payment row does not exist.
                $this->entityManager->detach($payment);
            }

            throw $e;
        }

        return [$payment, $paymentWasCreated, $amount, $reservation];
    }

    private function driver(PaymentGateway $gateway): PaymentGatewayInterface|\Throwable
    {
        try {
            return $this->paymentGatewayRegistry->resolve($gateway);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * One row, one invoice. A row with no expiresAt under a gateway with no lifetime is outside this and is started as
     * ever. Otherwise, in this order:
     *
     * - no provider reference but an expiresAt: a reservation. Running while it lasts, whichever gateway the customer
     *   chose now, because that start's invoice lands on this row; a stale one (a crash) means the row is free;
     * - pending with no provider reference: nothing to protect, the row is free;
     * - the chosen gateway's invoice, unexpired, for this amount: live;
     * - anything else carries an invoice that is over or is another gateway's, which the customer can still pay, so it
     *   must keep its references: a new row. That includes switching from a live invoice to a gateway with no lifetime.
     *   So does a submitted row, whose receipt is an admin's to judge: replacing it leaves it as it is (supersede()).
     */
    private function invoiceState(?Payment $payment, PaymentGateway $gateway, ?int $lifetime, int $amount, \DateTimeImmutable $now): string
    {
        if (!$payment instanceof Payment) {
            return self::INVOICE_START;
        }
        $expiresAt = $payment->getExpiresAt();
        $transactionId = $payment->getGatewayTransactionId();
        if (null === $lifetime && null === $expiresAt) {
            return self::INVOICE_START;
        }
        if (null === $transactionId && null !== $expiresAt) {
            return $expiresAt > $now ? self::INVOICE_RESERVED : self::INVOICE_START;
        }
        if (null === $transactionId && null === $payment->getAuthority() && PaymentStatus::PENDING === $payment->getStatus()) {
            return self::INVOICE_START;
        }
        $live = null !== $lifetime
            && null !== $transactionId
            && null !== $expiresAt && $expiresAt > $now
            && $payment->getGateway()?->getId() === $gateway->getId()
            && $payment->getPayableAmount() === $amount;

        return $live ? self::INVOICE_LIVE : self::INVOICE_REPLACE;
    }

    /** Closed unpaid, not failed; the order stays open and a later "paid" goes to review (PaymentSettlementService). */
    private function supersede(Payment $payment): void
    {
        if (PaymentStatus::PENDING !== $payment->getStatus()) {
            return;
        }
        $payment
            ->setStatus(PaymentStatus::REJECTED)
            ->setAdminNote(Payment::NOTE_GATEWAY_INVOICE_SUPERSEDED)
            ->setConfirmationSource(Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED);
    }

    /**
     * After createPayment(), whatever it answered: a row with an invoice has its expiry, the plugin's own or the lifetime
     * from now; a row without one is free again.
     */
    private function endReservation(Payment $payment, \DateTimeImmutable $reservation, int $lifetime): void
    {
        if (null === $payment->getGatewayTransactionId()) {
            $payment->setExpiresAt(null);
        } elseif ($reservation === $payment->getExpiresAt()) {
            $payment->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d seconds', $lifetime)));
        }
    }

    /**
     * A payment of method "wallet" whose debit is held: waiting for its settlement (pending), in review, or waiting for
     * an admin retry (provisioning_failed). A confirmed one paid the order; a rejected one gave its debit back. Also
     * used by ReceiptSubmission (no card receipt while such a debit is held).
     */
    public function heldWalletPayment(Order $order): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order, 'method' => Payment::METHOD_WALLET, 'status' => self::HELD_WALLET_STATUSES], ['id' => 'DESC']);
    }

    /** The order's open gateway payment; a wallet payment is never one (it is never handed to a gateway). */
    private function openPayment(Order $order): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->where('p.order = :order')
            ->andWhere('p.status IN (:statuses)')
            ->andWhere('p.method <> :wallet')
            ->setParameter('order', $order)
            ->setParameter('statuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->setParameter('wallet', Payment::METHOD_WALLET)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * openPayment() as a plain read, then the row by primary key (order, then payment, as PaymentSettlementService::lock()).
     * Under the order lock nothing else closes it, but the plain read may be older than that lock inside an outer
     * transaction, and a managed entity keeps the fields it had: the locked row decides.
     */
    private function lockedOpenPayment(Order $order): ?Payment
    {
        $payment = $this->openPayment($order);
        if (!$payment instanceof Payment) {
            return null;
        }
        $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);

        return in_array($payment->getStatus(), [PaymentStatus::PENDING, PaymentStatus::SUBMITTED], true) ? $payment : null;
    }

    /** NOWPayments refused the amount as below its minimum: the payment cannot be paid at all. */
    private function rejectBelowMinimum(Payment $payment, bool $paymentWasCreated): void
    {
        $payment
            ->setStatus(PaymentStatus::REJECTED)
            ->setFailedAt(new \DateTimeImmutable())
            ->setGatewayTransactionId(null)
            ->setAuthority(null)
            ->setPaymentUrl(null);

        if ($paymentWasCreated) {
            $payment
                ->setCryptoPaymentId(null)
                ->setCryptoPaymentStatus(null)
                ->setCryptoAddress(null)
                ->setCryptoPayAmount(null)
                ->setCryptoPayCurrency(null)
                ->setCryptoPriceCurrency(null)
                ->setCryptoPurchaseId(null)
                ->setCryptoInvoiceId(null)
                ->setCryptoInvoiceUrl(null)
                ->setCryptoNetwork(null)
                ->setCryptoExpiresAt(null);
        }
    }
}
