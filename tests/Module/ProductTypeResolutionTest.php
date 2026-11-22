<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Shop\Domain\OrderType;
use PHPUnit\Framework\TestCase;

/**
 * PM2a: ProductModuleResolver reads vpn_service.product_type and plan.product_type. A renewal or add-traffic order
 * belongs to the module of the service it changes (its type wins over the plan's); any other order to its plan's
 * module. A type without a module is the registry's ModuleRegistryException, passed on uncaught.
 */
final class ProductTypeResolutionTest extends TestCase
{
    private ProductModuleInterface $vpn;
    private ProductModuleInterface $server;
    private ProductModuleResolver $resolver;

    protected function setUp(): void
    {
        $this->vpn = $this->module('vpn');
        $this->server = $this->module('server');
        $this->resolver = new ProductModuleResolver(new ProductModuleRegistry([$this->vpn, $this->server]));
    }

    public function testTheDefaultsAreVpn(): void
    {
        self::assertSame('vpn', (new Plan())->getProductType());
        self::assertSame('vpn', (new VpnService())->getProductType());
        self::assertSame(Plan::DEFAULT_PRODUCT_TYPE, VpnService::DEFAULT_PRODUCT_TYPE);
        self::assertSame(\App\Provisioning\Application\VpnProductModule::TYPE, Plan::DEFAULT_PRODUCT_TYPE);
        self::assertNull((new Plan())->getModuleConfig());
    }

    public function testAServiceResolvesByItsOwnType(): void
    {
        self::assertSame($this->vpn, $this->resolver->forService(new VpnService()));
        self::assertSame($this->server, $this->resolver->forService((new VpnService())->setProductType('server')));
    }

    public function testANewServiceOrderResolvesByItsPlan(): void
    {
        self::assertSame($this->server, $this->resolver->forOrder($this->order(OrderType::NEW_SERVICE, 'server')));
        self::assertSame($this->vpn, $this->resolver->forOrder($this->order(OrderType::NEW_SERVICE, 'vpn')));
        // A target on a new-service order is not the service it changes: the plan decides.
        self::assertSame($this->server, $this->resolver->forOrder($this->order(OrderType::NEW_SERVICE, 'server', 'vpn')));
    }

    public function testARenewalOrAddTrafficOrderResolvesByItsTargetServiceWhenTheTypesDiffer(): void
    {
        foreach ([OrderType::RENEWAL, OrderType::ADD_TRAFFIC] as $type) {
            self::assertSame($this->vpn, $this->resolver->forOrder($this->order($type, 'server', 'vpn')), $type.': the target service wins');
            self::assertSame($this->server, $this->resolver->forOrder($this->order($type, 'vpn', 'server')), $type.': the target service wins');
            self::assertSame($this->server, $this->resolver->forOrder($this->order($type, 'server')), $type.' without a target: the plan');
        }
    }

    public function testAnOrderWithoutAPlanKeepsTheDefault(): void
    {
        self::assertSame($this->vpn, $this->resolver->forOrder((new Order())->setType(OrderType::WALLET_TOPUP)));
    }

    public function testATypeWithoutAModuleThrowsTheTypedException(): void
    {
        $calls = [
            'service x' => fn () => $this->resolver->forService((new VpnService())->setProductType('x')),
            'plan x' => fn () => $this->resolver->forOrder($this->order(OrderType::NEW_SERVICE, 'x')),
            'renewal of a service x' => fn () => $this->resolver->forOrder($this->order(OrderType::RENEWAL, 'vpn', 'x')),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name.' must throw');
            } catch (ModuleRegistryException $e) {
                self::assertSame(ModuleRegistryException::UNKNOWN_MODULE, $e->reason, $name);
            }
        }
    }

    private function order(string $type, string $planType, ?string $targetType = null): Order
    {
        $order = (new Order())->setType($type)->setPlan((new Plan())->setProductType($planType));
        if (null !== $targetType) {
            $order->setTargetService((new VpnService())->setProductType($targetType));
        }

        return $order;
    }

    private function module(string $type): ProductModuleInterface
    {
        $module = $this->createStub(ProductModuleInterface::class);
        $module->method('type')->willReturn($type);

        return $module;
    }
}
