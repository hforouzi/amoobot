<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Payment\Application\Settlement\PaymentSettlementService;

final class SmokeTest extends IntegrationTestCase
{
    public function testFixturesAndDummyPanelProvisioning(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);

        $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame([], $this->http->requests('gateway.zibal.ir'));
    }
}
