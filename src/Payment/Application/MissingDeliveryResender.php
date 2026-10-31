<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The scheduled half of M2e E1 (app:automation:run --only=orders): a new service's delivery message that is still due
 * (its settlement's process died before or while sending it, or nothing reached the customer) is sent once, by
 * PaymentCustomerNotifier::resendMissing() (which claims it, so a concurrent retry never sends it twice). One whose
 * payment is no longer confirmed or whose service was deleted is given up.
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
     * @return array{due: int, resent: int, givenUp: int}
     */
    public function run(int $limit, bool $dryRun = false): array
    {
        $summary = ['due' => 0, 'resent' => 0, 'givenUp' => 0];
        foreach ($this->deliveryLog->due($limit) as $row) {
            ++$summary['due'];
            $payment = $this->entityManager->find(Payment::class, $row['payment_id']);
            $service = $this->entityManager->find(VpnService::class, $row['service_id']);
            if (!$payment instanceof Payment || !$service instanceof VpnService || PaymentStatus::CONFIRMED !== $payment->getStatus() || VpnServiceStatus::DELETED === $service->getStatus()) {
                if (!$dryRun) {
                    $this->deliveryLog->giveUp($row['service_id'], $row['order_id']);
                }
                ++$summary['givenUp'];
                continue;
            }
            if (!$dryRun) {
                $this->customerNotifier->resendMissing($payment, $service);
                $after = $this->deliveryLog->state($row['service_id'], $row['order_id']);
                $summary['resent'] += PaymentDeliveryLog::SENT === $after ? 1 : 0;
            }
        }

        return $summary;
    }
}
