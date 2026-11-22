<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\ModuleRegistryException;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;

/**
 * PM2a on the database: rows written without the new columns (as every row before the migration, and any insert by
 * code that does not know them) read 'vpn'; provisioning gives a new service its plan's type (VpnProvisioningService,
 * the one place); a plan or service of a type without a module makes the resolver throw; a renewal is settled by the
 * module of the service it renews, whatever its plan's type.
 */
final class ProductTypeColumnsTest extends IntegrationTestCase
{
    public function testRowsWrittenWithoutTheColumnsReadAsVpn(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO plan (title, duration_days, price, is_active, created_at) VALUES ('old', 30, 1000, 1, NOW())");
        $planId = (int) $db->lastInsertId();
        $user = $this->createUser();
        $db->executeStatement("INSERT INTO vpn_service (user_id, status, created_at, public_token) VALUES (?, 'active', NOW(), ?)", [$user->getId(), VpnService::generatePublicToken()]);
        $serviceId = (int) $db->lastInsertId();
        $this->em->clear();

        $plan = $this->em->find(Plan::class, $planId) ?? self::fail('plan');
        $service = $this->em->find(VpnService::class, $serviceId) ?? self::fail('service');

        self::assertSame('vpn', $plan->getProductType());
        self::assertNull($plan->getModuleConfig());
        self::assertSame('vpn', $service->getProductType());
        self::assertSame(['vpn', null], [$this->row('plan', $planId)['product_type'], $this->row('plan', $planId)['module_config']]);
        self::assertSame('vpn', $this->row('vpn_service', $serviceId)['product_type']);
        self::assertSame('vpn', self::getContainer()->get(ProductModuleResolver::class)->forService($service)->type());
    }

    public function testModuleConfigIsStoredAsJson(): void
    {
        $plan = $this->createPlan()->setModuleConfig(['size' => 'cx22']);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['size' => 'cx22'], ($this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan'))->getModuleConfig());
    }

    public function testProvisioningGivesTheServiceItsPlansType(): void
    {
        $plan = $this->createPlan(150000, $this->createTestInbound())->setProductType('pm2a_other');
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);

        $service = self::getContainer()->get(VpnProvisioningService::class)->provisionOrder($order);
        $this->em->flush();

        self::assertSame('pm2a_other', $service->getProductType());
        self::assertSame('pm2a_other', $this->row('vpn_service', (int) $service->getId())['product_type']);
    }

    public function testASoldServiceIsVpnLikeItsPlan(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->settlement()->approveByAdmin($payment, 1, null, 'test')->outcome);

        self::assertSame('vpn', $this->em->getConnection()->fetchOne('SELECT product_type FROM vpn_service WHERE order_id = ?', [$order->getId()]));
    }

    public function testAPlanOrServiceOfATypeWithoutAModuleMakesTheResolverThrow(): void
    {
        $resolver = self::getContainer()->get(ProductModuleResolver::class);
        $plan = $this->createPlan()->setProductType('x');
        $service = $this->createActiveService($this->createUser())->setProductType('x');
        $this->em->flush();
        $this->em->clear();
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan');
        $service = $this->em->find(VpnService::class, $service->getId()) ?? self::fail('service');

        $calls = [
            'service' => static fn () => $resolver->forService($service),
            'order of the plan' => static fn () => $resolver->forOrder((new Order())->setType(OrderType::NEW_SERVICE)->setPlan($plan)),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name.' must throw');
            } catch (ModuleRegistryException $e) {
                self::assertSame(ModuleRegistryException::UNKNOWN_MODULE, $e->reason, $name);
                self::assertStringContainsString('"x"', $e->getMessage());
            }
        }
    }

    public function testANewServiceOfAPlanWithoutAModuleIsARecordedProvisioningFailure(): void
    {
        $plan = $this->createPlan(150000, $this->createTestInbound())->setProductType('x');
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(0, $this->driver()->createCalls, 'the panel is never reached');
    }

    public function testARenewalIsSettledByTheModuleOfTheServiceItRenews(): void
    {
        $inbound = $this->createTestInbound();
        $user = $this->createUser();
        $service = $this->createActiveService($user, $inbound);
        $expiresBefore = $service->getExpiresAt();
        // The plan now says 'x' (no module); the service it renews is 'vpn': the service's module renews it.
        $plan = $this->createPlan(150000, $inbound)->setProductType('x');
        $this->em->flush();
        $order = $this->createOrder($user, $plan, OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(1, $this->driver()->renewCalls);
        $row = $this->row('vpn_service', (int) $service->getId());
        self::assertSame([VpnServiceStatus::ACTIVE, 'vpn'], [$row['status'], $row['product_type']]);
        self::assertGreaterThan($expiresBefore?->format('Y-m-d H:i:s'), (string) $row['expires_at']);
    }

    public function testARenewalOfAServiceWithoutAModuleFailsWhateverItsPlanSays(): void
    {
        $inbound = $this->createTestInbound();
        $user = $this->createUser();
        $service = $this->createActiveService($user, $inbound)->setProductType('x');
        $this->em->flush();
        $order = $this->createOrder($user, $this->createPlan(150000, $inbound), OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome, $result->message);
        self::assertSame(0, $this->driver()->renewCalls);
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }
}
