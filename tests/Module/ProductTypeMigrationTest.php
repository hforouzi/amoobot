<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Entity\VpnService;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PM2a, Version20261122090000: up() adds plan.product_type (NOT NULL DEFAULT 'vpn'), plan.module_config (JSON, NULL)
 * and vpn_service.product_type (NOT NULL DEFAULT 'vpn') with idx_vpn_service_type_status (product_type, status); rows
 * that existed before read 'vpn'. down() removes them again, and is refused (nothing changed) while a row is not a
 * plain VPN row. The migration runs in its own console process, as in deployment.
 */
final class ProductTypeMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const VERSION = 'DoctrineMigrations\\Version20261122090000';

    public function testDownThenUpKeepsEveryOldRowAndReadsItAsVpn(): void
    {
        try {
            $down = $this->migrate('down');
            self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            self::assertSame([], $this->newColumns());
            self::assertSame([], $this->indexColumns());

            // Rows as the code before PM2a wrote them.
            $db = $this->em->getConnection();
            $db->executeStatement("INSERT INTO plan (title, duration_days, price, is_active, created_at) VALUES ('before PM2a', 30, 1000, 1, NOW())");
            $planId = (int) $db->lastInsertId();
            $user = $this->createUser();
            foreach (['active', 'suspended'] as $status) {
                $db->executeStatement('INSERT INTO vpn_service (user_id, status, created_at, public_token) VALUES (?, ?, NOW(), ?)', [$user->getId(), $status, VpnService::generatePublicToken()]);
            }

            $up = $this->migrate('up');
            self::assertSame(0, $up->getExitCode(), $up->getOutput().$up->getErrorOutput());

            self::assertSame([
                'plan.module_config' => ['YES', null, 'longtext'],
                'plan.product_type' => ['NO', "'vpn'", 'varchar(32)'],
                'vpn_service.product_type' => ['NO', "'vpn'", 'varchar(32)'],
            ], $this->newColumns());
            self::assertSame(['product_type', 'status'], $this->indexColumns());
            self::assertSame(['vpn', null], array_values($db->fetchAssociative('SELECT product_type, module_config FROM plan WHERE id = ?', [$planId]) ?: []));
            self::assertSame(['vpn', 'vpn'], $db->fetchFirstColumn('SELECT product_type FROM vpn_service WHERE user_id = ? ORDER BY id', [$user->getId()]));
        } finally {
            $this->restore();
        }
    }

    public function testDownIsRefusedWhileARowIsNotAPlainVpnRow(): void
    {
        $service = $this->createActiveService($this->createUser())->setProductType('server');
        $plan = $this->createPlan()->setModuleConfig(['size' => 'cx22']);
        $this->em->flush();

        try {
            $down = $this->migrate('down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertConsoleContains('Irreversible: 1 plan(s) and 1 service(s) are not plain VPN rows', $down->getOutput().$down->getErrorOutput());
            self::assertCount(3, $this->newColumns(), 'nothing was dropped');
            self::assertSame('server', $this->row('vpn_service', (int) $service->getId())['product_type']);
            self::assertNotNull($this->row('plan', (int) $plan->getId())['module_config']);
            self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION]));
        } finally {
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

    /** @return array<string, array{string, string|null, string}> table.column => [nullable, default, type] */
    private function newColumns(): array
    {
        $columns = [];
        foreach ($this->em->getConnection()->fetchAllAssociative(
            "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND ((TABLE_NAME = 'plan' AND COLUMN_NAME IN ('product_type', 'module_config')) OR (TABLE_NAME = 'vpn_service' AND COLUMN_NAME = 'product_type'))
             ORDER BY TABLE_NAME, COLUMN_NAME",
        ) as $row) {
            $columns[$row['TABLE_NAME'].'.'.$row['COLUMN_NAME']] = [(string) $row['IS_NULLABLE'], null === $row['COLUMN_DEFAULT'] || 'NULL' === $row['COLUMN_DEFAULT'] ? null : (string) $row['COLUMN_DEFAULT'], strtolower((string) $row['COLUMN_TYPE'])];
        }

        return $columns;
    }

    /** @return list<string> */
    private function indexColumns(): array
    {
        return $this->em->getConnection()->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_service' AND INDEX_NAME = 'idx_vpn_service_type_status' ORDER BY SEQ_IN_INDEX");
    }
}
