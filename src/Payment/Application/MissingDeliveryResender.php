<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The scheduled half of M2e E1 (app:automation:run --only=orders): a new service's delivery message that is still due
 * (its settlement's process died before or while sending it, or nothing reached the customer) is sent once, by
 * PaymentCustomerNotifier::resendMissing() (which claims it, so a concurrent retry never sends it twice, and abandons it
 * when the service changed owner, is not active, or the delivery is older than PaymentDeliveryLog::MAX_AGE). One whose
 * payment is no longer confirmed or whose service is gone is abandoned here. Only counts are logged.
 */
final class MissingDeliveryResender
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentDeliveryLog $deliveryLog,
        private readonly PaymentCustomerNotifier $customerNotifier,
    ) {
    }

    /**
     * @return array{due: int, resent: int, abandoned: int}
     */
    public function run(int $limit, bool $dryRun = false): array
    {
        $summary = ['due' => 0, 'resent' => 0, 'abandoned' => 0];
        foreach ($this->deliveryLog->due($limit) as $row) {
            ++$summary['due'];
            $payment = $this->entityManager->find(Payment::class, $row['payment_id']);
            $service = $this->entityManager->find(VpnService::class, $row['service_id']);
            if (!$payment instanceof Payment || !$service instanceof VpnService || PaymentStatus::CONFIRMED !== $payment->getStatus()) {
                if (!$dryRun) {
                    $this->deliveryLog->abandon($row['service_id'], $row['order_id'], $payment instanceof Payment && $service instanceof VpnService ? 'payment_not_confirmed' : 'service_missing');
                }
                ++$summary['abandoned'];
                continue;
            }
            if (!$dryRun) {
                $this->customerNotifier->resendMissing($payment, $service);
                $after = $this->deliveryLog->state($row['service_id'], $row['order_id']);
                $summary['resent'] += PaymentDeliveryLog::SENT === $after ? 1 : 0;
                $summary['abandoned'] += PaymentDeliveryLog::ABANDONED === $after ? 1 : 0;
            }
        }

        return $summary;
    }
}
