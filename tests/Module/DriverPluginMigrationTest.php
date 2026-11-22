<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PM2b, Version20261122120000: up() adds plugin.source (NOT NULL DEFAULT 'package') and the core rows (vpn and
 * sanaei_3xui and dummy enabled, server disabled); existing gateway rows are untouched and read 'package'. down()
 * removes them again, and is refused (nothing changed) while a driver package is installed. up() is refused (nothing
 * changed) when a row already has one of the core codes. The migration runs in its own console process, as in
 * deployment.
 */
final class DriverPluginMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const VERSION = 'DoctrineMigrations\\Version20261122120000';
    private const GATEWAY = 'pm2b_migration_gateway';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code IN (?, 'pm2b_migration_driver')", [self::GATEWAY]);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement("DELETE FROM plugin WHERE code IN (?, 'pm2b_migration_driver')", [self::GATEWAY]);
        }
        parent::tearDown();
    }

    public function testDownThenUpLeavesGatewayRowsAloneAndInsertsTheCoreRows(): void
    {
        $db = $this->em->getConnection();
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertNull($this->sourceColumn());
            self::assertSame([], $db->fetchFirstColumn("SELECT code FROM plugin WHERE code IN ('vpn', 'server', 'sanaei_3xui', 'dummy')"));

            // A gateway row as the code before PM2b wrote it.
            $db->executeStatement(
                "INSERT INTO plugin (code, type, name, version, status, path, main_class, manifest, installed_at, created_at) VALUES (?, 'payment_gateway', '{\"en\":\"Gateway\"}', '1.2.3', 'enabled', 'var/plugins/x', 'X\\\\Y', '{\"manifestVersion\":1}', NOW(), NOW())",
                [self::GATEWAY],
            );
            $before = $db->fetchAssociative('SELECT * FROM plugin WHERE code = ?', [self::GATEWAY]) ?: [];

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());

            self::assertSame(['NO', "'package'", 'varchar(16)'], $this->sourceColumn());
            $after = $db->fetchAssociative('SELECT * FROM plugin WHERE code = ?', [self::GATEWAY]) ?: [];
            self::assertSame('package', $after['source']);
            unset($after['source']);
            self::assertSame($before, $after, 'the gateway row is unchanged');

            self::assertSame([
                ['code' => 'dummy', 'type' => 'vpn_driver', 'status' => 'enabled', 'source' => 'core', 'version' => 'core', 'path' => '', 'main_class' => null, 'enabled' => 1, 'disabled' => 0],
                ['code' => 'sanaei_3xui', 'type' => 'vpn_driver', 'status' => 'enabled', 'source' => 'core', 'version' => 'core', 'path' => '', 'main_class' => null, 'enabled' => 1, 'disabled' => 0],
                ['code' => 'server', 'type' => 'product_module', 'status' => 'disabled', 'source' => 'core', 'version' => 'core', 'path' => '', 'main_class' => null, 'enabled' => 0, 'disabled' => 1],
                ['code' => 'vpn', 'type' => 'product_module', 'status' => 'enabled', 'source' => 'core', 'version' => 'core', 'path' => '', 'main_class' => null, 'enabled' => 1, 'disabled' => 0],
            ], array_map(static fn (array $row): array => array_merge($row, ['enabled' => (int) $row['enabled'], 'disabled' => (int) $row['disabled']]), $db->fetchAllAssociative(
                "SELECT code, type, status, source, version, path, main_class, enabled_at IS NOT NULL AS enabled, disabled_at IS NOT NULL AS disabled FROM plugin WHERE source = 'core' ORDER BY code",
            )));
            self::assertSame('{"fa":"وی‌پی‌ان","en":"VPN"}', $db->fetchOne("SELECT name FROM plugin WHERE code = 'vpn'"));
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileADriverPackageIsInstalled(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO plugin (code, type, name, version, status, source, path, manifest, installed_at, created_at) VALUES ('pm2b_migration_driver', 'vpn_driver', '{}', '1.0.0', 'disabled', 'package', 'var/plugins/pm2b_migration_driver', '{}', NOW(), NOW())");

        try {
            $down = $this->migrate('down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible: 1 driver or module package(s) are installed', $down->getOutput().$down->getErrorOutput());
            self::assertNotNull($this->sourceColumn(), 'nothing was dropped');
            self::assertNotFalse($db->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION]));
        } finally {
            $db->executeStatement("DELETE FROM plugin WHERE code = 'pm2b_migration_driver'");
            $this->restore();
        }
    }

    public function testUpIsRefusedWhenACoreCodeIsTaken(): void
    {
        $db = $this->em->getConnection();
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            $db->executeStatement("INSERT INTO plugin (code, type, name, version, status, path, manifest, installed_at, created_at) VALUES ('dummy', 'payment_gateway', '{}', '1.0.0', 'disabled', 'var/plugins/dummy', '{}', NOW(), NOW())");

            $up = $this->migrate('up');

            self::assertNotSame(0, $up->getExitCode());
            self::assertConsoleContains('Plugin row(s) with the code(s) dummy exist already', $up->getOutput().$up->getErrorOutput());
            self::assertNull($this->sourceColumn(), 'nothing was changed');
        } finally {
            if (null === $this->sourceColumn()) {
                $db->executeStatement("DELETE FROM plugin WHERE code = 'dummy' AND type = 'payment_gateway'");
            }
            $this->restore();
        }
    }

    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::VERSION, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    /** The migration applied again if a test left it reverted, for the rest of the suite. */
    private function restore(): void
    {
        if (false === $this->em->getConnection()->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION])) {
            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());
        }
    }

    /** @return array{string, string|null, string}|null [nullable, default, type] of plugin.source, null when absent */
    private function sourceColumn(): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative("SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin' AND COLUMN_NAME = 'source'");

        return false === $row ? null : [(string) $row['IS_NULLABLE'], null === $row['COLUMN_DEFAULT'] ? null : (string) $row['COLUMN_DEFAULT'], strtolower((string) $row['COLUMN_TYPE'])];
    }
}
