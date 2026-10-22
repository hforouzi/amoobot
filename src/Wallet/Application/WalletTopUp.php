<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\Order;
use App\Entity\User;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Shop\Application\OrderTrackingCodeService;
use App\Wallet\Domain\WalletException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Starting a wallet top-up: a WALLET_TOPUP order (no plan) waiting for payment. It is then paid like any order, with
 * the store payment methods (PaymentStarter / StorePaymentMethodResolver), and settled by PaymentSettlementService,
 * which credits the wallet once the payment is confirmed. Nothing here touches the wallet.
 */
final class WalletTopUp
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletSettings $settings,
        private readonly WalletService $wallets,
        private readonly OrderTrackingCodeService $trackingCodes,
    ) {
    }

    /**
     * @param int $amount integer Toman, within the configured range
     *
     * @throws WalletException wallet_disabled, wallet_frozen, topup_out_of_range
     */
    public function createOrder(User $user, int $amount): Order
    {
        if (!$this->settings->enabled()) {
            throw new WalletException('wallet_disabled');
        }
        if (true === $this->wallets->find($user)?->isFrozen()) {
            throw new WalletException('wallet_frozen');
        }
        if (!$this->settings->isTopUpAmountAllowed($amount)) {
            throw new WalletException('topup_out_of_range', sprintf('A top-up is %d to %d Toman.', $this->settings->topUpMin(), $this->settings->topUpMax()));
        }

        $order = (new Order())
            ->setUser($user)
            ->setType(OrderType::WALLET_TOPUP)
            ->setAmount($amount)
            ->setStatus(OrderStatus::WAITING_PAYMENT)
            ->setMetadata(['walletTopUp' => true]);
        $this->trackingCodes->assignIfMissing($order);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /** The customer's own top-up order of this id while it can still be paid, else null. */
    public function payableOrder(User $user, int $orderId): ?Order
    {
        $order = $orderId > 0 ? $this->entityManager->getRepository(Order::class)->find($orderId) : null;
        if (!$order instanceof Order || !$order->isWalletTopUp() || $order->getUser()->getId() !== $user->getId() || OrderStatus::WAITING_PAYMENT !== $order->getStatus()) {
            return null;
        }

        return $order;
    }
}
