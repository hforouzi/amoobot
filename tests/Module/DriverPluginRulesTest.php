<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Module\Application\ModuleAllowList;
use App\Plugin\CoreVersion;
use App\Plugin\SemanticVersion;
use App\Plugin\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PM2b: the version ranges of "requires.core" (docs/design/product-modules.md 4.3) and APP_MODULES_ALLOWED (4.7).
 */
final class DriverPluginRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'inside a two-sided range' => ['2.4.1', '>=2.4 <3.0', true];
        yield 'the lower bound itself' => ['2.4.0', '>=2.4', true];
        yield 'below the lower bound' => ['2.3.9', '>=2.4 <3.0', false];
        yield 'the excluded upper bound' => ['3.0.0', '>=2.4 <3.0', false];
        yield 'exact, no operator' => ['2.4.1', '2.4.1', true];
        yield 'exact, another patch' => ['2.4.2', '=2.4.1', false];
        yield 'greater than' => ['2.4.1', '>2.4.0', true];
        yield 'at most' => ['2.4.1', '<=2.4', false];
        yield 'a pre-release is below its release' => ['3.0.0-rc.1', '<3.0', true];
        yield 'an unreadable range' => ['2.4.1', '^2.4', false];
        yield 'an empty range' => ['2.4.1', '', false];
        yield 'not a version' => ['two', '>=1', false];
    }

    #[DataProvider('ranges')]
    public function testRanges(string $version, string $range, bool $expected): void
    {
        self::assertSame($expected, VersionConstraint::satisfies($version, $range));
    }

    public function testValidRanges(): void
    {
        self::assertTrue(VersionConstraint::isValid('>=2.4 <3.0'));
        self::assertTrue(VersionConstraint::isValid('2'));
        self::assertFalse(VersionConstraint::isValid('>= 2.4'));
        self::assertFalse(VersionConstraint::isValid('~2.4'));
        self::assertFalse(VersionConstraint::isValid('>=2.4 || <1'));
    }

    public function testTheCoreVersionIsASemanticVersion(): void
    {
        self::assertTrue(SemanticVersion::isValid(CoreVersion::CURRENT));
    }

    public function testTheAllowListIsCommaSeparatedAndDefaultsToVpn(): void
    {
        self::assertSame(['vpn'], (new ModuleAllowList())->modules());
        $list = new ModuleAllowList(' vpn , Server,,vpn');
        self::assertSame(['vpn', 'server'], $list->modules());
        self::assertTrue($list->allows('server'));
        self::assertFalse($list->allows('file'));
        self::assertSame([], (new ModuleAllowList(''))->modules());
        self::assertFalse((new ModuleAllowList(''))->allows('vpn'));
    }
}
