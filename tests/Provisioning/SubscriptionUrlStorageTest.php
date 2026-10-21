<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;

/**
 * The drivers return the complete subscription URL (base + prefix + sub id); provisioning must store it as is, not
 * append the sub id a second time (".../sub/<id>/<id>", which clients cannot import) or produce a relative "/<id>".
 */
final class SubscriptionUrlStorageTest extends IntegrationTestCase
{
    public function testTheDriversSubscriptionUrlIsStoredAsIs(): void
    {
        $service = $this->provision();

        self::assertSame('https://panel.test/sub/'.$service['sub_id'], $service['subscription_url']);
    }

    public function testWithoutASubscriptionBaseNoUrlIsStored(): void
    {
        $this->driver()->subscriptionBase = null;

        $service = $this->provision();

        self::assertNull($service['subscription_url'], 'not a relative "/<sub id>"');
    }

    /**
     * @return array<string, mixed>
     */
    private function provision(): array
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test');
        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);

        $service = $this->em->getConnection()->fetchAssociative('SELECT * FROM vpn_service WHERE order_id = ?', [$order->getId()]);
        self::assertIsArray($service);

        return $service;
    }
}
