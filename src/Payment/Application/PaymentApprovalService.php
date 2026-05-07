<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

class PaymentApprovalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnProvisioningService $vpnProvisioningService,
    ) {
    }

    public function confirm(Payment $payment): PaymentApprovalResult
    {
        $order = $payment->getOrder();
        $existingService = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        if ($existingService instanceof VpnService) {
            if (PaymentStatus::CONFIRMED !== $payment->getStatus()) {
                $payment->setStatus(PaymentStatus::CONFIRMED);
            }
            if (null === $payment->getConfirmedAt()) {
                $payment->setConfirmedAt(new \DateTimeImmutable());
            }
            if (OrderStatus::PROVISIONED !== $order->getStatus()) {
                $order->setStatus(OrderStatus::PROVISIONED);
            }
            if (null === $order->getProvisionedAt()) {
                $order->setProvisionedAt(new \DateTimeImmutable());
            }
            if (null === $order->getPaidAt()) {
                $order->setPaidAt(new \DateTimeImmutable());
            }

            $this->entityManager->flush();

            return PaymentApprovalResult::alreadyProcessed('Payment was already processed.', $existingService);
        }

        if (PaymentStatus::REJECTED === $payment->getStatus()) {
            return PaymentApprovalResult::alreadyProcessed('Payment was already rejected.');
        }

        $payment
            ->setStatus(PaymentStatus::CONFIRMED)
            ->setConfirmedAt($payment->getConfirmedAt() ?? new \DateTimeImmutable());

        if (!in_array($order->getStatus(), [OrderStatus::PAID, OrderStatus::PROVISIONED], true)) {
            $order
                ->setStatus(OrderStatus::PAID)
                ->setPaidAt($order->getPaidAt() ?? new \DateTimeImmutable());
        }

        $vpnService = $this->vpnProvisioningService->provisionOrder($order);
        $this->entityManager->flush();

        return PaymentApprovalResult::processed('Payment confirmed and service provisioned.', $vpnService);
    }

    public function reject(Payment $payment, ?string $reason = null): PaymentApprovalResult
    {
        $order = $payment->getOrder();
        $existingService = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        if ($existingService instanceof VpnService || PaymentStatus::CONFIRMED === $payment->getStatus() || OrderStatus::PROVISIONED === $order->getStatus()) {
            return PaymentApprovalResult::alreadyProcessed('Payment has already been confirmed/provisioned.');
        }

        if (PaymentStatus::REJECTED === $payment->getStatus()) {
            return PaymentApprovalResult::alreadyProcessed('Payment was already rejected.');
        }

        $payment
            ->setStatus(PaymentStatus::REJECTED)
            ->setAdminNote($reason);

        $order->setStatus(OrderStatus::FAILED);
        $this->entityManager->flush();

        return PaymentApprovalResult::processed('Payment rejected.');
    }

    public function confirmPayment(Payment $payment): PaymentApprovalResult
    {
        return $this->confirm($payment);
    }

    public function rejectPayment(Payment $payment, ?string $reason = null): PaymentApprovalResult
    {
        return $this->reject($payment, $reason);
    }
}
