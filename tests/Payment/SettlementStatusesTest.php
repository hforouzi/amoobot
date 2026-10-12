<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Admin\UI\Support\AdminStatusBadge;
use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\IntegrationTestCase;

final class SettlementStatusesTest extends IntegrationTestCase
{
    public function testNewStatusesAreRegisteredForFiltersAndBadges(): void
    {
        self::assertContains(PaymentStatus::NEEDS_REVIEW, PaymentStatus::ALL);
        self::assertContains(PaymentStatus::PROVISIONING_FAILED, PaymentStatus::ALL);
        self::assertContains(OrderStatus::PROVISIONING_FAILED, OrderStatus::ALL);
        self::assertSame([PaymentStatus::PENDING, PaymentStatus::SUBMITTED], PaymentStatus::SETTLEABLE);

        self::assertStringContainsString('badge-warning', AdminStatusBadge::html(PaymentStatus::NEEDS_REVIEW));
        self::assertStringContainsString('badge-danger', AdminStatusBadge::html(PaymentStatus::PROVISIONING_FAILED));
        self::assertArrayHasKey('status.needs_review', AdminStatusBadge::choices(PaymentStatus::ALL));
    }

    public function testSettlementColumnsRoundTrip(): void
    {
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()));
        $payment
            ->setStatus(PaymentStatus::PROVISIONING_FAILED)
            ->setVerifiedMoney('1500000', 'IRR')
            ->setAmountFlag('overpaid')
            ->setReviewReason('underpaid: expected 150000 IRT')
            ->setProvisioningError('panel down')
            ->setProvisioningFailedAt(new \DateTimeImmutable())
            ->incrementProvisioningAttempts()
            ->setConfirmationSource('admin')
            ->setConfirmedBy(7, '12345');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Payment::class, $payment->getId());
        self::assertInstanceOf(Payment::class, $reloaded);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $reloaded->getStatus());
        self::assertSame('1500000', $reloaded->getVerifiedAmount());
        self::assertSame(1, $reloaded->getProvisioningAttempts());
        self::assertSame(7, $reloaded->getConfirmedByAdminId());
        self::assertSame('12345', $reloaded->getConfirmedByTelegramId());
    }
}
