<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Payment\Application\PaymentApprovalService;

final class SmokeTest extends IntegrationTestCase
{
    public function testFixturesAndCurrentManualApprovalProvisionAService(): void
    {
        $user = $this->createUser();
        $order = $this->createOrder($user, $this->createPlan());
        $payment = $this->createPayment($order);

        $result = self::getContainer()->get(PaymentApprovalService::class)->confirm($payment, 'test');

        self::assertTrue($result->processed, $result->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame([], $this->http->requests('gateway.zibal.ir'));
    }
}
