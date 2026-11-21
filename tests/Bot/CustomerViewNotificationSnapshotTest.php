<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Provisioning\Application\ServiceNotificationService;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\CustomerViewFixtures;
use App\Tests\Support\CustomerViewSnapshot;
use App\Tests\Support\IntegrationTestCase;

/**
 * Golden files of the notices a customer gets about a VPN service (ServiceNotificationService), recorded before PM1d:
 * expiry warnings (SERVICE_NOTIFY_EXPIRY_DAYS=3,1), traffic thresholds (SERVICE_NOTIFY_TRAFFIC_THRESHOLDS=80,95,100,
 * from .env; .env.test does not override them) and "expired". Each run sends what is due, newest service first.
 */
final class CustomerViewNotificationSnapshotTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use CustomerViewFixtures;

    private const GIB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCustomerView();
    }

    protected function tearDown(): void
    {
        $this->tearDownCustomerView();
        parent::tearDown();
    }

    public function testExpiryWarnings(): void
    {
        // 2.5 days left: the 3-day warning. 12 hours left: the 3-day and the 1-day warning.
        $this->stateService('near_expiry');
        $this->stateService('active')->setExpiresAt($this->now->modify('+12 hours'));
        $this->em->flush();
        $this->http->reset();

        $this->notifications()->sendExpiryWarnings();

        CustomerViewSnapshot::assertTranscript('notify-expiry', $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testTrafficThresholds(): void
    {
        // 82 %: the 80 % warning; 96 %: 80 and 95; 100 %: 80, 95 and "exhausted".
        $this->stateService('active')->setTrafficUsedGb(41)->setTrafficUsedBytes(41 * self::GIB);
        $this->stateService('near_expiry')->setTrafficUsedGb(48)->setTrafficUsedBytes(48 * self::GIB);
        $this->stateService('traffic_exhausted');
        $this->em->flush();
        $this->http->reset();

        $this->notifications()->sendTrafficWarnings();

        CustomerViewSnapshot::assertTranscript('notify-traffic', $this->transcript(), $this->now, $this->serviceNames);
    }

    public function testExpired(): void
    {
        $this->stateService('expired');
        $this->http->reset();

        $this->notifications()->sendExpiredNotifications();

        CustomerViewSnapshot::assertTranscript('notify-expired', $this->transcript(), $this->now, $this->serviceNames);
    }

    private function notifications(): ServiceNotificationService
    {
        return self::getContainer()->get(ServiceNotificationService::class);
    }
}
