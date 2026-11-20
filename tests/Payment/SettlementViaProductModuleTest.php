<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\RenewalResult;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\VpnProductModule;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ProgrammableVpnPanelDriver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * PM1b (docs/design/product-modules.md 9): settlement fulfils a new service and a renewal through the 'vpn' product
 * module, found in ProductModuleRegistry. This pins the call path: exactly one module call, inside the settlement
 * transaction and before the payment is confirmed, with the arguments settlement passed to the services before; an
 * add-traffic or wallet top-up order never calls the module; an unknown module type is an ordinary provisioning
 * failure (rolled back, recorded, retryable, no ledger row).
 *
 * The registry is replaced, before settlement is first built, by one holding a spy around the real VpnProductModule.
 */
final class SettlementViaProductModuleTest extends IntegrationTestCase
{
    private const PRICE = 150000; // Toman

    /** @var \ArrayObject<int, array{method: string, args: list<mixed>, inTransaction: bool, paymentStatus: string|false}> */
    private \ArrayObject $calls;

    private int $paymentId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = new \ArrayObject();
    }

    private function useModules(ProductModuleInterface ...$modules): void
    {
        self::getContainer()->set(ProductModuleRegistry::class, new ProductModuleRegistry($modules));
    }

    private function spyOnVpnModule(Payment $payment): void
    {
        $real = self::getContainer()->get('test.module.vpn_product_module');
        self::assertInstanceOf(VpnProductModule::class, $real);
        $this->paymentId = (int) $payment->getId();
        $this->useModules($this->spy($real));
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }

    private static function paid(string $amount, string $currency): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx1', amount: $amount, currency: $currency));
    }

    private function walletRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction');
    }

    public function testANewServiceIsProvisionedThroughTheVpnModuleOnceInsideTheTransaction(): void
    {
        $inbound = $this->createTestInbound();
        $plan = $this->createPlan(self::PRICE, $inbound);
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->spyOnVpnModule($payment);

        $result = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'webhook');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertCount(1, $this->calls, 'exactly one module call');
        $call = $this->calls[0];
        self::assertSame('provision', $call['method']);
        self::assertTrue($call['inTransaction'], 'called inside the settlement transaction');
        self::assertSame(PaymentStatus::PENDING, $call['paymentStatus'], 'called before the payment is confirmed (before commit)');
        self::assertSame($order->getId(), $call['args'][0] instanceof Order ? $call['args'][0]->getId() : null);
        self::assertSame([
            'source' => 'webhook',
            'orderId' => $order->getId(),
            'paymentId' => $payment->getId(),
            'planId' => $plan->getId(),
            'planInboundId' => $inbound->getId(),
            'panelId' => $inbound->getPanel()->getId(),
            'driverType' => ProgrammableVpnPanelDriver::TYPE,
        ], $call['args'][1], 'the metadata settlement passed to provisionOrder() before');
        self::assertSame(1, $this->driver()->lookupCalls, 'adoption is on: the panel is searched for the order\'s client first');
        self::assertSame(1, $this->driver()->createCalls);
        self::assertSame(1, $this->countServicesForOrder($order));
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testARenewalGoesThroughTheVpnModuleOnceInsideTheTransaction(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(self::PRICE, $inbound), OrderType::RENEWAL, null, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->spyOnVpnModule($payment);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertCount(1, $this->calls, 'exactly one module call');
        $call = $this->calls[0];
        self::assertSame('renew', $call['method']);
        self::assertTrue($call['inTransaction']);
        self::assertSame(PaymentStatus::SUBMITTED, $call['paymentStatus']);
        self::assertInstanceOf(VpnService::class, $call['args'][0]);
        self::assertSame($service->getId(), $call['args'][0]->getId(), 'the order\'s target service');
        self::assertInstanceOf(Order::class, $call['args'][1]);
        self::assertSame($order->getId(), $call['args'][1]->getId());
        self::assertSame(1, $this->driver()->renewCalls);
        self::assertSame(100, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb'], '50 GB carried over + 50 GB renewal');
    }

    public function testAddTrafficNeverCallsTheModule(): void
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $service = $this->createActiveService($user, $inbound);
        $order = $this->createOrder($user, $this->createPlan(self::PRICE, $inbound), OrderType::ADD_TRAFFIC, 20000, $service);
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->spyOnVpnModule($payment);

        $result = $this->settlement()->settle($payment, self::paid('20000', 'IRT'), 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertCount(0, $this->calls);
        self::assertSame(60, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb']);
    }

    public function testAWalletTopUpNeverCallsTheModule(): void
    {
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(50000)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->spyOnVpnModule($payment);

        $result = $this->settlement()->approveByAdmin($payment, 1, null, 'test');

        self::assertTrue($result->isConfirmed(), $result->message);
        self::assertCount(0, $this->calls);
        self::assertSame(1, $this->walletRows(), 'the top-up was credited');
    }

    public function testAnUnknownModuleTypeIsARecordedProvisioningFailureAndRetryable(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(self::PRICE, $this->createTestInbound()));
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $paymentId = (int) $payment->getId();
        $real = self::getContainer()->get('test.module.vpn_product_module');
        self::assertInstanceOf(ProductModuleInterface::class, $real);
        // Only a module of another type: get('vpn') throws ModuleRegistryException (UNKNOWN_MODULE).
        $this->paymentId = $paymentId;
        $this->useModules($this->spy($real, 'server'));

        $failed = $this->settlement()->settle($payment, self::paid('1500000', 'IRR'), 'webhook');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $failed->outcome, $failed->message);
        self::assertCount(0, $this->calls);
        self::assertSame(0, $this->driver()->createCalls + $this->driver()->lookupCalls, 'the panel is never reached');
        self::assertSame(0, $this->countServicesForOrder($order));
        $row = $this->row('payment', $paymentId);
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $row['status']);
        self::assertNull($row['confirmed_at']);
        self::assertSame(1, (int) $row['provisioning_attempts']);
        self::assertStringContainsString(ModuleRegistryException::unknownModule('vpn')->getMessage(), (string) $row['provisioning_error']);
        self::assertSame(OrderStatus::PROVISIONING_FAILED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame(0, $this->walletRows(), 'no ledger row, as for any provisioning failure of a gateway payment');

        // A fresh container with the real registry: the admin retry fulfils the same payment.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->driver()->reset();
        $payment = $this->em->find(Payment::class, $paymentId) ?? throw new \LogicException('payment');

        $retry = $this->settlement()->retryProvisioning($payment, 3, null, 'easyadmin_retry_provisioning');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(1, $this->countServicesForOrder($order));
        $row = $this->row('payment', $paymentId);
        self::assertSame(PaymentStatus::CONFIRMED, $row['status']);
        self::assertSame(2, (int) $row['provisioning_attempts']);
        self::assertNull($row['provisioning_error']);
    }

    /**
     * A module that delegates to the real one and records each call: its arguments, whether a transaction was open,
     * and the payment's status as the settlement connection sees it at that moment.
     */
    private function spy(ProductModuleInterface $inner, ?string $type = null): ProductModuleInterface
    {
        return new class($inner, $this->calls, $this->em->getConnection(), $this->paymentId, $type) implements ProductModuleInterface {
        /**
         * @param \ArrayObject<int, array{method: string, args: list<mixed>, inTransaction: bool, paymentStatus: string|false}> $calls
         */
        public function __construct(
            private readonly ProductModuleInterface $inner,
            private readonly \ArrayObject $calls,
            private readonly Connection $connection,
            private readonly int $paymentId,
            private readonly ?string $type = null,
        ) {
        }

        public function type(): string
        {
            return $this->type ?? $this->inner->type();
        }

        public function contractVersion(): int
        {
            return $this->inner->contractVersion();
        }

        public function provision(Order $order, array $meta = []): VpnService
        {
            $this->record('provision', [$order, $meta]);

            return $this->inner->provision($order, $meta);
        }

        public function renew(VpnService $service, Order $order): RenewalResult
        {
            $this->record('renew', [$service, $order]);

            return $this->inner->renew($service, $order);
        }

        public function suspend(VpnService $service): ServiceAdminOutcome
        {
            $this->record('suspend', [$service]);

            return $this->inner->suspend($service);
        }

        public function resume(VpnService $service): ServiceAdminOutcome
        {
            $this->record('resume', [$service]);

            return $this->inner->resume($service);
        }

        public function terminate(VpnService $service): ServiceAdminOutcome
        {
            $this->record('terminate', [$service]);

            return $this->inner->terminate($service);
        }

        /** @param list<mixed> $args */
        private function record(string $method, array $args): void
        {
            $status = $this->connection->fetchOne('SELECT status FROM payment WHERE id = ?', [$this->paymentId]);
            $this->calls[] = [
                'method' => $method,
                'args' => $args,
                'inTransaction' => $this->connection->isTransactionActive(),
                'paymentStatus' => is_string($status) ? $status : false,
            ];
        }
        };
    }
}
