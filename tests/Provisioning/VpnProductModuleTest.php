<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\VpnProductModule;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;

/**
 * VpnProductModule (PM1a) only delegates to today's services: what it returns and changes is what those services do.
 * Nothing in production calls it yet.
 */
final class VpnProductModuleTest extends IntegrationTestCase
{
    public function testTheRegistryFindsTheVpnModule(): void
    {
        $registry = self::getContainer()->get(ProductModuleRegistry::class);

        self::assertSame(['vpn'], $registry->types());
        self::assertInstanceOf(VpnProductModule::class, $registry->get('vpn'));
        self::assertSame(1, $registry->get('vpn')->contractVersion());
    }

    public function testProvisionIsIdempotentPerOrder(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(inbound: $this->createTestInbound()));

        $first = $this->module()->provision($order, ['source' => 'test']);
        $second = $this->module()->provision($order, ['source' => 'test']);

        self::assertSame(VpnServiceStatus::ACTIVE, $first->getStatus());
        self::assertCount(1, $this->driver()->createRequests, 'the second call adopts the client the first one created');
        self::assertSame($first->getRemoteId(), $second->getRemoteId());
    }

    public function testRenewDelegatesToTheRenewalService(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(inbound: $inbound);
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $plan, OrderType::RENEWAL, target: $service);

        $result = $this->module()->renew($service, $order);

        self::assertSame($service, $result->service);
        self::assertNotNull($result->newExpiresAt);
    }

    public function testSuspendResumeAndTerminateAreTheAdminOutcomes(): void
    {
        $service = $this->createActiveService($this->createUser(), $this->createTestInbound());

        self::assertSame(ServiceAdminOutcome::DONE, $this->module()->suspend($service));
        self::assertSame(VpnServiceStatus::SUSPENDED, $service->getStatus());
        self::assertSame(ServiceAdminOutcome::ALREADY_SUSPENDED, $this->module()->suspend($service));
        self::assertSame(ServiceAdminOutcome::DONE, $this->module()->resume($service));
        self::assertSame(VpnServiceStatus::ACTIVE, $service->getStatus());
        self::assertSame(ServiceAdminOutcome::DONE, $this->module()->terminate($service));
        self::assertSame(VpnServiceStatus::DELETED, $service->getStatus());
        self::assertSame(ServiceAdminOutcome::ALREADY_DELETED, $this->module()->terminate($service));
        self::assertSame(ServiceAdminOutcome::NOT_ALLOWED, $this->module()->resume($service));
        $this->em->clear();
        self::assertSame(VpnServiceStatus::DELETED, $this->em->find(VpnService::class, $service->getId())?->getStatus());
    }

    private function module(): VpnProductModule
    {
        return self::getContainer()->get(VpnProductModule::class);
    }
}
