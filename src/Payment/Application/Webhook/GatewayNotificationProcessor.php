<?php

declare(strict_types=1);

namespace App\Payment\Application\Webhook;

use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Domain\Webhook\GatewayNotification;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one pipeline for every gateway callback/webhook:
 *
 *   active gateway -> handleNotification() (authentication, fail-closed)
 *   -> payment lookup ONLY by references stored at createPayment time, scoped to that gateway
 *   -> verifyPayment() against the provider
 *   -> PaymentSettlementService
 *
 * Nothing in the request (status, amount, order id, internal payment id) is trusted.
 */
final class GatewayNotificationProcessor
{
    /** Payment columns holding references a gateway returned when the payment was created. */
    private const REFERENCE_FIELDS = ['gatewayTransactionId', 'authority', 'cryptoPaymentId', 'cryptoInvoiceId'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentSettlementService $settlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PaymentGateway> $gateways active candidate gateways (e.g. all active Zibal gateways)
     */
    public function process(array $gateways, Request $request, string $channel, string $source): NotificationOutcome
    {
        if ([] === $gateways) {
            return new NotificationOutcome(404, 'gateway_not_found');
        }

        $rejection = null;
        foreach ($gateways as $gateway) {
            $driver = $this->driverFor($gateway);
            if (!$driver instanceof NotificationAwareGatewayInterface) {
                $rejection ??= GatewayNotification::rejected(404, 'notifications_not_supported');
                continue;
            }

            $notification = $driver->handleNotification($gateway, $request, $channel);
            if (!$notification->authenticated) {
                $rejection = $notification;
                continue;
            }

            $payment = $this->findPayment($gateway, $notification->references);
            if (!$payment instanceof Payment) {
                continue;
            }

            return $this->processPayment($driver, $payment, $notification, $source);
        }

        if (null !== $rejection) {
            $this->logger->warning('Payment notification rejected.', ['source' => $source, 'reason' => $rejection->rejectReason]);

            return new NotificationOutcome($rejection->rejectStatus, $rejection->rejectReason);
        }

        return new NotificationOutcome(404, 'payment_not_found');
    }

    private function processPayment(NotificationAwareGatewayInterface&PaymentGatewayInterface $driver, Payment $payment, GatewayNotification $notification, string $source): NotificationOutcome
    {
        if (!$payment->acceptsGatewayVerification()) {
            return new NotificationOutcome(200, 'already_'.$payment->getStatus(), $payment);
        }

        if ($notification->reportedNotPaid) {
            return new NotificationOutcome(200, 'not_paid', $payment);
        }

        try {
            $verification = $driver->verifyPayment($payment, $notification->payload);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Payment verification failed.', ['payment_id' => $payment->getId(), 'source' => $source, 'error' => $e->getMessage()]);

            return new NotificationOutcome(500, 'verification_failed', $payment);
        }

        if ($verification->success && $verification->paid) {
            $result = $this->settlement->settle($payment, VerifiedPayment::fromGateway($verification), $source);

            return new NotificationOutcome($result->isRecorded() ? 200 : 500, $result->outcome->value, $result->payment ?? $payment, $result);
        }

        if ($verification->success && $verification->failed) {
            $result = $this->settlement->recordGatewayFailure($payment, $source);

            return new NotificationOutcome($result->isRecorded() ? 200 : 500, 'failed', $result->payment ?? $payment);
        }

        return new NotificationOutcome(200, 'pending', $payment);
    }

    private function driverFor(PaymentGateway $gateway): ?PaymentGatewayInterface
    {
        try {
            return $this->gatewayRegistry->resolve($gateway);
        } catch (\Throwable $e) {
            $this->logger->warning('Payment gateway driver unavailable.', ['gateway_id' => $gateway->getId(), 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<string> $references
     */
    private function findPayment(PaymentGateway $gateway, array $references): ?Payment
    {
        if ([] === $references) {
            return null;
        }

        $qb = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p')
            ->where('p.gateway = :gateway')
            ->setParameter('gateway', $gateway)
            ->setParameter('refs', $references)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1);

        $or = $qb->expr()->orX();
        foreach (self::REFERENCE_FIELDS as $field) {
            $or->add($qb->expr()->in('p.'.$field, ':refs'));
        }

        $payment = $qb->andWhere($or)->getQuery()->getOneOrNullResult();

        return $payment instanceof Payment ? $payment : null;
    }
}
