<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Module\Domain\CapabilityNotSupported;
use App\Module\Domain\CapabilitySet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilitySetTest extends TestCase
{
    public function testNamesAreSortedAndUnique(): void
    {
        $set = CapabilitySet::of('resume', 'adopt_existing', 'resume');

        self::assertSame(['adopt_existing', 'resume'], $set->names());
        self::assertTrue($set->has('resume'));
        self::assertFalse($set->has('multi_inbound'));
        self::assertTrue($set->equals(CapabilitySet::of('adopt_existing', 'resume')));
        self::assertFalse($set->equals(CapabilitySet::of('adopt_existing')));
        self::assertSame([], CapabilitySet::none()->names());
    }

    public function testRequireThrowsForAnUndeclaredCapability(): void
    {
        $set = CapabilitySet::of('resume');
        $set->require('resume', 'x');

        try {
            $set->require('multi_inbound', 'sanaei_3xui_legacy');
            self::fail('undeclared');
        } catch (CapabilityNotSupported $e) {
            self::assertSame('multi_inbound', $e->capability);
            self::assertSame('sanaei_3xui_legacy', $e->driverCode);
            self::assertSame('Driver "sanaei_3xui_legacy" does not declare capability "multi_inbound".', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Resume'];
        yield 'dash' => ['multi-inbound'];
        yield 'leading digit' => ['1x'];
        yield 'space' => ['a b'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidNames')]
    public function testAnInvalidNameIsRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapabilitySet::of($name);
    }
}
