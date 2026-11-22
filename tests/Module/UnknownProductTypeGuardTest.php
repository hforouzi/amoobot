<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\VpnService;
use App\Module\Application\ProductModuleRegistry;
use App\Module\Application\UnknownProductTypeGuard;
use App\Module\Domain\ProductModuleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * PM2a: UnknownProductTypeGuard, the jobs' fail-closed check. A type with a module passes silently; a type without one
 * is skipped with one warning line per service and run ("unknown_product_type service_id=.. type=..", no context, no
 * customer data); reset() starts a new run. A type is logged printable only.
 */
final class UnknownProductTypeGuardTest extends TestCase
{
    public function testOneLinePerServiceAndRun(): void
    {
        $logger = $this->logger();
        $guard = new UnknownProductTypeGuard(fn (): ProductModuleRegistry => new ProductModuleRegistry([$this->module('vpn')]), $logger);

        self::assertFalse($guard->skips(new VpnService()));
        self::assertFalse($guard->skipsRow(5, 'vpn'));
        self::assertSame([], $logger->records);

        self::assertTrue($guard->skipsRow(7, 'x'));
        self::assertTrue($guard->skipsRow(7, 'x'), 'skipped every time');
        self::assertTrue($guard->skipsRow(8, 'server'));
        self::assertSame([
            ['warning', 'unknown_product_type service_id=7 type=x', []],
            ['warning', 'unknown_product_type service_id=8 type=server', []],
        ], $logger->records);

        $guard->reset();
        self::assertTrue($guard->skipsRow(7, 'x'));
        self::assertCount(3, $logger->records, 'a new run logs it again');
    }

    public function testATypeIsLoggedPrintableOnly(): void
    {
        $logger = $this->logger();
        $guard = new UnknownProductTypeGuard(fn (): ProductModuleRegistry => new ProductModuleRegistry([$this->module('vpn')]), $logger);

        self::assertTrue($guard->skipsRow(1, "a b\nc"));
        self::assertTrue($guard->skipsRow(2, ''));
        self::assertSame(['unknown_product_type service_id=1 type=a?b?c', 'unknown_product_type service_id=2 type=""'], array_column($logger->records, 1));
    }

    private function module(string $type): ProductModuleInterface
    {
        $module = $this->createStub(ProductModuleInterface::class);
        $module->method('type')->willReturn($type);

        return $module;
    }

    /** @return AbstractLogger&object{records: list<array{mixed, string, array<mixed>}>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
    }
}
