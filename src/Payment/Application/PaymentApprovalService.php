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

    public function confirm(Payment $payment, string $source = 'payment_approval'): PaymentApprovalResult
    {
        $order = $payment->getOrder();
        $plan = $order->getPlan();
        $inbound = $plan->getInbound();
        $panel = $inbound?->getPanel();
        $existingService = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order]);

        error_log(sprintf(
            '[PaymentApprovalService] confirm_start source=%s payment_id=%d order_id=%d plan_id=%d plan_inbound_id=%d panel_id=%d panel_type="%s"',
            $source,
            $payment->getId() ?? 0,
            $order->getId() ?? 0,
            $plan->getId() ?? 0,
            $inbound?->getId() ?? 0,
            $panel?->getId() ?? 0,
            (string) ($panel?->getType() ?? '')
        ));

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

        try {
            $vpnService = $this->vpnProvisioningService->provisionOrder($order, [
                'source' => $source,
                'orderId' => $order->getId() ?? 0,
                'paymentId' => $payment->getId() ?? 0,
                'planId' => $plan->getId() ?? 0,
                'planInboundId' => $inbound?->getId() ?? 0,
                'panelId' => $panel?->getId() ?? 0,
                'driverType' => (string) ($panel?->getType() ?? 'dummy'),
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PaymentApprovalService] provisioning_failed source=%s payment_id=%d order_id=%d plan_id=%d plan_inbound_id=%d panel_id=%d message="%s"',
                $source,
                $payment->getId() ?? 0,
                $order->getId() ?? 0,
                $plan->getId() ?? 0,
                $inbound?->getId() ?? 0,
                $panel?->getId() ?? 0,
                $e->getMessage()
            ));

            return new PaymentApprovalResult(false, false, 'ساخت کاربر در پنل انجام نشد. لاگ را بررسی کنید.');
        }

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

}
