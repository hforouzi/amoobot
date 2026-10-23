<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Bot\Application\StorePaymentMethodResolver;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
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
 */
final class PaymentStarter
{
    /** The statuses in which a wallet payment holds its debit (heldWalletPayment()). */
    private const HELD_WALLET_STATUSES = [PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorePaymentMethodResolver $methodResolver,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly SettingValueProvider $settingValueProvider,
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
        $gateway = $storePaymentMethod->getGateway();
        if (OrderType::NEW_SERVICE === $order->getType() && !$order->requirePlan()->isActive()) {
            return new PaymentStart(PaymentStart::KIND_PLAN_INACTIVE, null, false, null, 0);
        }

        $claimed = $this->claimPayment($order, $storePaymentMethod);
        if (is_string($claimed)) {
            return new PaymentStart($claimed, null, false, null, 0);
        }
        [$payment, $paymentWasCreated, $amount] = $claimed;

        $requestResult = $this->paymentGatewayRegistry
            ->resolve($gateway)
            ->createPayment($payment, $order);

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
     * the customer pay twice. Then the PaymentStart kind to answer (KIND_WALLET_PAYMENT_PENDING / _HELD).
     *
     * @return array{Payment, bool, int}|string [payment, created, amount], or the refusal's PaymentStart kind
     */
    private function claimPayment(Order $order, StorePaymentMethod $storePaymentMethod): array|string
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

                return PaymentStatus::PENDING === $held->getStatus() ? PaymentStart::KIND_WALLET_PAYMENT_PENDING : PaymentStart::KIND_WALLET_PAYMENT_HELD;
            }

            $amount = $this->payableAmount($order);
            $payment = $this->openPayment($order);
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

        return [$payment, $paymentWasCreated, $amount];
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
