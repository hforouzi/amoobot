<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentDisplayState;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where a customer's payment stands, read from the database only: the Mini App polls this, so it must never ask a
 * gateway or confirm anything (that happens through the gateway's webhook, callback or return page, or an admin).
 */
final class PaymentProgress
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function ownedPayment(User $user, int $paymentId): ?Payment
    {
        $payment = $paymentId > 0 ? $this->entityManager->getRepository(Payment::class)->find($paymentId) : null;

        return $payment instanceof Payment && $payment->getOrder()->getUser()->getId() === $user->getId() ? $payment : null;
    }

    public function state(Payment $payment): string
    {
        return PaymentDisplayState::forCustomer($payment);
    }

    /** The service the paid order created or changed, once the payment is paid. */
    public function service(Payment $payment): ?VpnService
    {
        if (PaymentDisplayState::PAID !== $this->state($payment)) {
            return null;
        }
        $order = $payment->getOrder();
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }

        return $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order], ['id' => 'DESC']);
    }

    /**
     * @return array{state: string, final: bool, serviceId: ?int}
     */
    public function snapshot(Payment $payment): array
    {
        $state = $this->state($payment);

        return ['state' => $state, 'final' => PaymentDisplayState::isFinal($state), 'serviceId' => $this->service($payment)?->getId()];
    }
}
