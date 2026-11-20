<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Application\ProductModuleResolver;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Provisioning\Application\VpnProductModule;
use PHPUnit\Framework\TestCase;

/**
 * PM1c: ProductModuleResolver answers 'vpn' for every order and service until PM2a adds product_type; an unknown type is
 * the registry's ModuleRegistryException, passed on uncaught.
 */
final class ProductModuleResolverTest extends TestCase
{
    private function module(string $type): ProductModuleInterface
    {
        $module = $this->createStub(ProductModuleInterface::class);
        $module->method('type')->willReturn($type);

        return $module;
    }

    public function testEveryOrderAndServiceBelongsToTheVpnModule(): void
    {
        $vpn = $this->module(VpnProductModule::TYPE);
        $resolver = new ProductModuleResolver(new ProductModuleRegistry([$this->module('server'), $vpn]));

        self::assertSame($vpn, $resolver->forService(new VpnService()));
        self::assertSame($vpn, $resolver->forOrder(new Order()));
        self::assertSame($vpn, $resolver->forType(VpnProductModule::TYPE));
    }

    public function testAnUnknownTypeIsTheRegistrysTypedException(): void
    {
        $resolver = new ProductModuleResolver(new ProductModuleRegistry([$this->module('server')]));
        $calls = [
            'forService' => static fn () => $resolver->forService(new VpnService()),
            'forOrder' => static fn () => $resolver->forOrder(new Order()),
            'forType' => static fn () => $resolver->forType('nope'),
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
}
