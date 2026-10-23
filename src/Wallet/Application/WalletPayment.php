<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\Order;
use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementResult;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletReferenceType;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Paying a new service / renewal / add-traffic order from the customer's wallet, as one more payment method.
 *
 * 1. open(): under the order's row lock, a payment of method "wallet" (no gateway) is created and the wallet is
 *    debited by the order's payable amount, in ONE transaction: either both exist or neither does. The debit's
 *    idempotency key is the payment id, so a repeated call reuses the payment and never debits twice.
 * 2. pay(): that payment is confirmed through PaymentSettlementService::settle() like any gateway payment (the wallet
 *    ledger row is the verification): amount check, duplicate protection, fulfilment, PROVISIONING_FAILED + retry,
 *    receipts and audit are the existing ones. Every order still has exactly one confirmed payment.
 *
 * The debit stays when provisioning fails (the admin retry provisions without a new debit); a rejected / cancelled /
 * expired wallet payment gets its debit back once (PaymentSettlementService / IncompleteOrderExpiryService).
 *
 * Partial wallet payment (part wallet, part gateway) is out of scope: the wallet must cover the whole payable amount
 * (balance + credit limit), otherwise nothing is debited and the customer picks another method.
 */
final class WalletPayment
{
    /** Payment::confirmationSource of a wallet settlement. */
    public const SOURCE = 'wallet';
    public const PAYABLE_ORDER_TYPES = [OrderType::NEW_SERVICE, OrderType::RENEWAL, OrderType::ADD_TRAFFIC];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly WalletSettings $settings,
        private readonly WalletService $wallets,
        private readonly PaymentStarter $paymentStarter,
        private readonly PaymentSettlementService $settlement,
    ) {
    }

    /** Whether to offer "pay from wallet" for this order: switched on, payable, not frozen, and the wallet covers it. */
    public function canPay(Order $order): bool
    {
        if (!$this->settings->paymentEnabled() || !in_array($order->getType(), self::PAYABLE_ORDER_TYPES, true) || OrderStatus::WAITING_PAYMENT !== $order->getStatus()) {
            return false;
        }
        $wallet = $this->wallets->find($order->getUser());

        return null !== $wallet && !$wallet->isFrozen() && $wallet->getAvailable() >= $this->paymentStarter->payableAmount($order);
    }

    /**
     * Pays the order from the wallet and settles it.
     *
     * @throws WalletException wallet_payment_disabled, order_not_payable, plan_unavailable, payment_in_progress,
     *                         wallet_frozen, wallet_insufficient: nothing was debited
     */
    public function pay(Order $order): SettlementResult
    {
        $payment = $this->open($order);
        $debit = $this->wallets->findTransaction(WalletKeys::purchase((int) $payment->getId())) ?? throw new \LogicException('A wallet payment without its debit.');

        return $this->settlement->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(
            success: true,
            paid: true,
            transactionId: 'wallet:'.$debit->getId(),
            amount: (string) -$debit->getAmount(),
            currency: AmountNormalizer::SHOP_CURRENCY,
        )), self::SOURCE);
    }

    /**
     * The order's wallet payment with its debit, created atomically (or the existing unsettled one, already debited).
     *
     * @throws WalletException see pay()
     */
    public function open(Order $order): Payment
    {
        if (!$this->settings->paymentEnabled()) {
            throw new WalletException('wallet_payment_disabled');
        }
        if (!in_array($order->getType(), self::PAYABLE_ORDER_TYPES, true)) {
            throw new WalletException('order_not_payable');
        }

        $orderId = (int) $order->getId();
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $connection->beginTransaction();
        $created = null;

        try {
            $order = $this->entityManager->find(Order::class, $orderId) ?? throw new WalletException('order_not_payable');
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (OrderStatus::WAITING_PAYMENT !== $order->getStatus()) {
                throw new WalletException('order_not_payable');
            }
            if (OrderType::NEW_SERVICE === $order->getType() && !$order->requirePlan()->isActive()) {
                throw new WalletException('plan_unavailable');
            }

            $payment = $this->unsettledWalletPayment($order);
            if (!$payment instanceof Payment) {
                $amount = $this->paymentStarter->payableAmount($order);
                $payment = $created = (new Payment())
                    ->setOrder($order)
                    ->setMethod(Payment::METHOD_WALLET)
                    ->setGatewayType(Payment::METHOD_WALLET)
                    ->setCurrency(AmountNormalizer::SHOP_CURRENCY)
                    ->setAmount($amount)
                    ->setPayableAmount($amount)
                    ->setStatus(PaymentStatus::PENDING);
                $this->entityManager->persist($payment);
                $this->entityManager->flush();
            }

            // Same key for a reused payment: the existing debit is returned, nothing is taken twice.
            $this->wallets->debit(
                $order->getUser(),
                (int) $payment->getPayableAmount(),
                WalletTransactionType::PURCHASE,
                WalletKeys::purchase((int) $payment->getId()),
                WalletActor::system(),
                WalletReferenceType::PAYMENT,
                (string) $payment->getId(),
                sprintf('order #%d (%s)', $orderId, $order->getType()),
            );
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            } elseif ($created instanceof Payment) {
                // Rolled back: the payment row does not exist.
                $this->entityManager->detach($created);
            }

            throw $e;
        }

        return $payment;
    }

    /**
     * The order's pending wallet payment (debited, settlement not finished), after checking that no other payment is
     * in the way: a confirmed / in-review / failed one means the order is paid; a submitted receipt is being checked.
     */
    private function unsettledWalletPayment(Order $order): ?Payment
    {
        $walletPayment = null;
        foreach ($this->entityManager->getRepository(Payment::class)->findBy(['order' => $order], ['id' => 'ASC']) as $payment) {
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $status = $payment->getStatus();
            if (in_array($status, [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED], true)) {
                throw new WalletException('order_not_payable');
            }
            if (PaymentStatus::SUBMITTED === $status) {
                throw new WalletException('payment_in_progress');
            }
            if (PaymentStatus::PENDING === $status && $payment->isWalletPayment()) {
                $walletPayment = $payment;
            }
        }

        return $walletPayment;
    }
}
