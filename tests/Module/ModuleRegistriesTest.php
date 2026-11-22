<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Application\ProviderDriverRegistry;
use App\Module\Domain\CapabilitySet;
use App\Module\Domain\CustomerActionInput;
use App\Module\Domain\CustomerActionResult;
use App\Module\Domain\CustomerActions;
use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProductModuleInterface;
use App\Module\Domain\ProviderDriverInterface;
use App\Provisioning\Application\AutoSuspendSummary;
use App\Provisioning\Application\RenewalResult;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\VpnCustomerView;
use PHPUnit\Framework\TestCase;

/**
 * Lookup by product type and by module + driver code; unknown and duplicate are typed failures.
 */
final class ModuleRegistriesTest extends TestCase
{
    public function testAModuleIsFoundByItsType(): void
    {
        $vpn = self::module('vpn');
        $registry = new ProductModuleRegistry([self::module('server'), $vpn]);

        self::assertSame($vpn, $registry->get('vpn'));
        self::assertTrue($registry->has('server'));
        self::assertFalse($registry->has('file'));
        self::assertSame(['server', 'vpn'], $registry->types());
    }

    public function testAnUnknownTypeIsATypedFailure(): void
    {
        $registry = new ProductModuleRegistry([self::module('vpn')]);

        try {
            $registry->get('server');
            self::fail('unknown');
        } catch (ModuleRegistryException $e) {
            self::assertSame(ModuleRegistryException::UNKNOWN_MODULE, $e->reason);
            self::assertSame('No product module of type "server".', $e->getMessage());
        }
    }

    public function testATypeRegisteredTwiceIsRefused(): void
    {
        $registry = new ProductModuleRegistry([self::module('vpn'), self::module('vpn')]);

        try {
            $registry->has('vpn');
            self::fail('duplicate');
        } catch (ModuleRegistryException $e) {
            self::assertSame(ModuleRegistryException::DUPLICATE_MODULE, $e->reason);
        }
    }

    public function testADriverIsFoundByModuleAndCode(): void
    {
        $modern = self::driver('vpn', 'sanaei_3xui');
        $hetzner = self::driver('server', 'hetzner_cloud');
        $registry = new ProviderDriverRegistry([self::driver('vpn', 'dummy'), $modern, $hetzner]);

        self::assertSame($modern, $registry->get('vpn', 'sanaei_3xui'));
        self::assertSame($hetzner, $registry->get('server', 'hetzner_cloud'));
        self::assertSame(['dummy', 'sanaei_3xui'], $registry->codes('vpn'));
        self::assertSame([], $registry->codes('file'));
        self::assertFalse($registry->has('server', 'sanaei_3xui'), 'a code is per module');
    }

    public function testAnUnknownDriverIsATypedFailure(): void
    {
        $registry = new ProviderDriverRegistry([self::driver('vpn', 'dummy')]);

        foreach ([['vpn', 'marzban'], ['server', 'dummy']] as [$module, $code]) {
            try {
                $registry->get($module, $code);
                self::fail('unknown');
            } catch (ModuleRegistryException $e) {
                self::assertSame(ModuleRegistryException::UNKNOWN_DRIVER, $e->reason);
                self::assertSame(sprintf('No "%s" driver with code "%s".', $module, $code), $e->getMessage());
            }
        }
    }

    public function testACodeRegisteredTwiceInOneModuleIsRefused(): void
    {
        $registry = new ProviderDriverRegistry([self::driver('vpn', 'sanaei_3xui'), self::driver('vpn', 'sanaei_3xui')]);

        try {
            $registry->codes('vpn');
            self::fail('duplicate');
        } catch (ModuleRegistryException $e) {
            self::assertSame(ModuleRegistryException::DUPLICATE_DRIVER, $e->reason);
        }
    }

    public function testTheSameCodeInTwoModulesIsAllowed(): void
    {
        $registry = new ProviderDriverRegistry([self::driver('vpn', 'acme'), self::driver('server', 'acme')]);

        self::assertSame('server', $registry->get('server', 'acme')->module());
    }

    private static function module(string $type): ProductModuleInterface
    {
        return new class($type) implements ProductModuleInterface {
            public function __construct(private readonly string $type)
            {
            }

            public function type(): string
            {
                return $this->type;
            }

            public function contractVersion(): int
            {
                return 1;
            }

            public function provision(Order $order, array $meta = []): VpnService
            {
                throw new \LogicException('not used');
            }

            public function renew(VpnService $service, Order $order): RenewalResult
            {
                throw new \LogicException('not used');
            }

            public function suspend(VpnService $service): ServiceAdminOutcome
            {
                throw new \LogicException('not used');
            }

            public function resume(VpnService $service): ServiceAdminOutcome
            {
                throw new \LogicException('not used');
            }

            public function terminate(VpnService $service): ServiceAdminOutcome
            {
                throw new \LogicException('not used');
            }

            public function runLifecycleTask(string $task, int $limit, bool $dryRun): AutoSuspendSummary
            {
                throw new \LogicException('not used');
            }

            public function customerView(VpnService $service): VpnCustomerView
            {
                throw new \LogicException('not used');
            }

            public function customerActions(VpnService $service, string $channel): CustomerActions
            {
                throw new \LogicException('not used');
            }

            public function runCustomerAction(VpnService $service, string $action, CustomerActionInput $input): CustomerActionResult
            {
                throw new \LogicException('not used');
            }
        };
    }

    private static function driver(string $module, string $code): ProviderDriverInterface
    {
        return new class($module, $code) implements ProviderDriverInterface {
            public function __construct(private readonly string $module, private readonly string $code)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function module(): string
            {
                return $this->module;
            }

            public function contractVersion(): int
            {
                return 1;
            }

            public function capabilities(): CapabilitySet
            {
                return CapabilitySet::none();
            }
        };
    }
}
