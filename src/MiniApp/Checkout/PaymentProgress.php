<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentDisplayState;
use App\Shop\Application\OrderServiceAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where a customer's payment stands, read from the database only: the Mini App polls this, so it must never ask a
 * gateway or confirm anything (that happens through the gateway's webhook, callback or return page, or an admin).
 */
final class PaymentProgress
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderServiceAccess $orderServices,
    ) {
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

    /**
     * The service the paid order created or changed, once the payment is paid, and only while it still belongs to the
     * order's customer (a service handed over with a claim link is not his to open any more: OrderServiceAccess).
     */
    public function service(Payment $payment): ?VpnService
    {
        if (PaymentDisplayState::PAID !== $this->state($payment)) {
            return null;
        }

        return $this->orderServices->ownedServiceOf($payment->getOrder());
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
